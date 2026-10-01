<?php
/* ============================================================================
   Deposit a work.

   Objective 1  stores the file and its description
   Objective 2  writes Dublin Core, the PREMIS object and the ingest events
   Objective 3  one form that explains itself and routes to the right reviewer

   Who may deposit:
     Faculty and the library only -- see canDeposit() for why. Faculty deposit
     student work on the student's behalf, naming the student in dc.creator.

   Routing:
     faculty  -> submitted, goes straight to the library queue
     admin    -> published immediately
   ============================================================================ */

require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/includes/metadata.php';
requireDeposit();

$me = currentUser($pdo);

$departments = $pdo->query("SELECT id, code, name FROM departments WHERE is_active = 1 ORDER BY name")->fetchAll();

/* ---- editing an existing record ---------------------------------------------
   The same form revises a deposit. The owner can edit while it is a draft, sent
   back for revision, or returned; the library can edit anything. The current
   file is kept unless a new one is attached -- and even then it is not deleted,
   only marked as the previous version, because a preservation system keeps
   what it was given. */
$editId  = (int)($_GET['id'] ?? $_POST['record_id'] ?? 0);
$editing = null;
$currentFile = null;
if ($editId > 0) {
    $st = $pdo->prepare("SELECT * FROM records WHERE id = ?");
    $st->execute([$editId]);
    $editing = $st->fetch() ?: null;
    if (!$editing || !canEditRecord($editing)) {
        flash('danger', 'That record cannot be edited right now.');
        redirect('my-work.php');
    }
    $cf = $pdo->prepare("SELECT * FROM record_files WHERE record_id = ? AND superseded_at IS NULL ORDER BY id DESC LIMIT 1");
    $cf->execute([$editId]);
    $currentFile = $cf->fetch() ?: null;
}
/* Saving an edit to work that is not public sends it back to the library. */
$resend = $editing && in_array($editing['status'], ['draft', 'revision', 'rejected'], true);

$errors = [];
$in = [
    'dc_title' => '', 'dc_creator' => '', 'dc_contributor' => '', 'dc_subject' => '',
    'dc_description' => '', 'dc_type' => 'Capstone Project', 'dc_language' => 'English',
    'adviser' => '', 'panel' => '', 'dc_rights' => '', 'dc_coverage' => '', 'department_id' => (string)($me['department_id'] ?? ''),
    'access_level' => 'open', 'year_completed' => date('Y'), 'page_count' => '',
];

if ($editing && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    foreach ($in as $k => $_) {
        if (array_key_exists($k, $editing)) $in[$k] = (string)($editing[$k] ?? '');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    foreach ($in as $k => $_) {
        if ($k === 'panel') continue;                 /* several fields, handled below */
        $in[$k] = trim((string)($_POST[$k] ?? ''));
    }

    /* One text field per panel member. Blank rows are dropped and the rest are
       kept as a single list, so the stored record, dc.contributor and the
       record page all read the same as they did with one box. */
    $in['panel'] = implode('; ', array_filter(
        array_map('trim', (array)($_POST['panel'] ?? [])),
        static fn($v) => $v !== ''
    ));

    /* ---- validate the description (Dublin Core required set) ---- */
    if ($in['dc_title'] === '')       $errors['dc_title']       = 'Give the work its full title.';
    if ($in['dc_creator'] === '')     $errors['dc_creator']     = 'Name at least one author.';
    if (str_word_count($in['dc_description']) < 30)
                                      $errors['dc_description'] = 'Write an abstract of at least 30 words.';
    if (count(splitList($in['dc_subject'])) < 3)
                                      $errors['dc_subject']     = 'Give at least three keywords, separated by semicolons.';
    if (!in_array($in['dc_type'], DOC_TYPES, true))
                                      $errors['dc_type']        = 'Choose what kind of work this is.';
    if ($in['department_id'] === '')  $errors['department_id']  = 'Choose the department this belongs to.';
    if (!array_key_exists($in['access_level'], ACCESS_LEVELS))
                                      $errors['access_level']   = 'Choose who may read the full text.';
    else $in['dc_rights'] = rightsStatementFor($in['access_level']);

    $in['dc_contributor'] = implode('; ', array_filter(array_merge(
        [trim($in['adviser'])], array_map('trim', splitList($in['panel']))
    ), static fn($v) => $v !== ''));

    /* ---- validate the file ---- */
    $file    = $_FILES['manuscript'] ?? null;
    $hasFile = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if (!$hasFile && $editing) {
        /* no new file: the current one stays */
    } elseif (!$hasFile) {
        $errors['manuscript'] = 'Attach the manuscript.';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $errors['manuscript'] = 'The upload did not finish. Try again.';
    } else {
        $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
            $errors['manuscript'] = 'Only PDF files are accepted. In Word, use File → Save As → PDF.';
        } elseif ((int)$file['size'] > MAX_UPLOAD_BYTES) {
            $errors['manuscript'] = 'That file is ' . humanBytes((int)$file['size'])
                                  . '. The limit is ' . humanBytes(MAX_UPLOAD_BYTES) . '.';
        } else {
            /* The name says .pdf; check the contents agree. A Word file renamed
               to .pdf would otherwise be stored as a PDF that opens nowhere. */
            $head = (string)@file_get_contents($file['tmp_name'], false, null, 0, 1024);
            $type = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
            if (!str_contains($head, '%PDF-') || $type !== 'application/pdf') {
                $errors['manuscript'] = 'That file is not a real PDF. In Word, use File → Save As → PDF, then attach that.';
            }
        }
    }

    /* ---- save a revision ---- */
    if (!$errors && $editing) {
        try {
            $pdo->beginTransaction();
            $agent = premisAgentForUser($pdo, currentUserId(), (string)$me['name']);

            $pdo->prepare(
                "UPDATE records SET dc_title=?, dc_creator=?, dc_contributor=?, adviser=?, panel=?, dc_subject=?,
                        dc_description=?, dc_type=?, dc_language=?, dc_rights=?, dc_coverage=?,
                        department_id=?, access_level=?, year_completed=?, page_count=?
                 WHERE id = ?"
            )->execute([
                $in['dc_title'], $in['dc_creator'], $in['dc_contributor'] ?: null,
                $in['adviser'] ?: null, $in['panel'] ?: null,
                $in['dc_subject'], $in['dc_description'], $in['dc_type'], $in['dc_language'],
                $in['dc_rights'], $in['dc_coverage'] ?: null,
                (int)$in['department_id'], $in['access_level'],
                $in['year_completed'] !== '' ? (int)$in['year_completed'] : null,
                $in['page_count'] !== '' ? (int)$in['page_count'] : null,
                $editId,
            ]);
            premisEvent($pdo, $editId, 'metadata modification', 'success',
                'Description revised by ' . $me['name'], $agent);

            $pdo->prepare("UPDATE premis_rights SET rights_statement = ? WHERE record_id = ?")
                ->execute([$in['dc_rights'], $editId]);

            if ($hasFile) {
                $pdo->prepare("UPDATE record_files SET superseded_at = NOW() WHERE record_id = ? AND superseded_at IS NULL")
                    ->execute([$editId]);
                storeManuscript($pdo, $editId, $file, currentUserId(), (string)$me['name'],
                    'Revised manuscript received from ' . $me['name'] . '; the previous version is kept');
            }

            if ($resend) {
                $pdo->prepare("UPDATE records SET status = 'submitted', submitted_at = NOW() WHERE id = ?")
                    ->execute([$editId]);
                premisEvent($pdo, $editId, 'submission', 'success', 'Revised and sent for review', $agent);
                notifyLibrary($pdo, 'A revised deposit needs review',
                    $me['name'] . ' revised “' . mb_strimwidth($in['dc_title'], 0, 70, '…') . '”.', 'review', $editId);
            }

            buildMetsPackage($pdo, $editId);
            logActivity($pdo, currentUserId(), 'revised a deposit', $in['dc_title'], $editId);
            $pdo->commit();

            flash('ok', $resend
                ? 'Saved and sent to the library for review. It returns under the same identifier when it is published.'
                : 'Changes saved.' . ($editing['status'] === 'published' ? ' They are live now.' : ''));
            redirect('record.php?id=' . $editId);
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors['manuscript'] = 'Could not save the changes: ' . $ex->getMessage();
        }
    }

    /* ---- store it ---- */
    if (!$errors && !$editing) {
        try {
            $pdo->beginTransaction();

            $isAdmin  = isAdmin();
            $status   = $isAdmin ? 'published' : 'submitted';
            $adviserId = null;   /* the library is the reviewer for every deposit */

            $stmt = $pdo->prepare(
                "INSERT INTO records
                   (dc_title, dc_creator, dc_contributor, adviser, panel, dc_subject, dc_description,
                    dc_type, dc_language, dc_rights, dc_coverage, dc_format,
                    department_id, access_level, status, submitted_by, adviser_id,
                    year_completed, page_count, submitted_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())"
            );
            $stmt->execute([
                $in['dc_title'], $in['dc_creator'], $in['dc_contributor'] ?: null,
                $in['adviser'] ?: null, $in['panel'] ?: null,
                $in['dc_subject'], $in['dc_description'], $in['dc_type'],
                $in['dc_language'], $in['dc_rights'] ?: null, $in['dc_coverage'] ?: null,
                mime_content_type($file['tmp_name']) ?: null,
                (int)$in['department_id'], $in['access_level'], $status,
                currentUserId(), $adviserId,
                $in['year_completed'] !== '' ? (int)$in['year_completed'] : null,
                $in['page_count'] !== '' ? (int)$in['page_count'] : null,
            ]);
            $recordId = (int)$pdo->lastInsertId();

            /* ---- move the file, fingerprint it, record it in PREMIS ---- */
            $stored = storeManuscript($pdo, $recordId, $file, currentUserId(), (string)$me['name'],
                'Deposit received from ' . $me['name']);
            $target    = $stored['path'];
            $depositor = premisAgentForUser($pdo, currentUserId(), (string)$me['name']);

            /* ---- rights ---- */
            $pdo->prepare(
                "INSERT INTO premis_rights (record_id, rights_basis, rights_statement, granting_agent, granted_at)
                 VALUES (?, 'license', ?, ?, CURDATE())"
            )->execute([$recordId, $in['dc_rights'], $me['name']]);

            /* ---- METS package ---- */
            /* The structural map uses the standard outline. Asking every depositor
               to type their chapter headings added work to the form and gave the
               reader nothing: no page numbers were captured, so a chapter could
               not be jumped to anyway. */
            buildMetsPackage($pdo, $recordId);

            /* ---- publish immediately for admins ---- */
            if ($isAdmin) {
                $identifier = mintIdentifier($pdo);
                $pdo->prepare(
                    "UPDATE records SET dc_identifier = ?, dc_date_issued = CURDATE(),
                            published_at = NOW(), published_by = ? WHERE id = ?"
                )->execute([$identifier, currentUserId(), $recordId]);
                premisEvent($pdo, $recordId, 'publication', 'success',
                    'Published as ' . $identifier, $depositor);
            } else {
                /* tell the reviewer there is something waiting */
                if ($adviserId) {
                    notify($pdo, $adviserId, 'A deposit needs your review',
                        $me['name'] . ' submitted “' . mb_strimwidth($in['dc_title'], 0, 70, '…') . '”.',
                        'review', $recordId);
                } else {
                    notifyLibrary($pdo, 'A deposit needs review',
                        $me['name'] . ' submitted “' . mb_strimwidth($in['dc_title'], 0, 70, '…') . '”.',
                        'review', $recordId);
                }
            }

            logActivity($pdo, currentUserId(), 'Deposited a work', $in['dc_title'], $recordId);
            $pdo->commit();

            flash('ok', $isAdmin
                ? 'Published. The record is now in the public collection.'
                : 'Deposited. The library has been notified and will review it.');
            redirect('record.php?id=' . $recordId);

        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (!empty($target) && is_file($target)) @unlink($target);
            $errors['manuscript'] = 'Could not save the deposit: ' . $ex->getMessage();
        }
    }
}

/* Live completeness meter reflects what has been typed so far */
$completeness = metadataCompleteness([
    'dc_title'       => $in['dc_title'],
    'dc_creator'     => $in['dc_creator'],
    'dc_subject'     => $in['dc_subject'],
    'dc_description' => $in['dc_description'],
    'dc_type'        => $in['dc_type'],
    'dc_language'    => $in['dc_language'],
    'dc_contributor' => $in['dc_contributor'],
    'dc_coverage'    => $in['dc_coverage'],
    'dc_rights'      => $in['dc_rights'],
]);

$pageLabel = $editing ? 'Revise this deposit' : 'Deposit your work';
$pageSub   = $editing
    ? ($resend
        ? 'Saving sends it back to the library, which publishes it again under the same identifier.'
        : ($editing['status'] === 'published'
            ? 'This work is public. Your changes go live as soon as you save.'
            : 'Your changes are saved to the record.'))
    : (isAdmin()
        ? 'Your deposits are published immediately.'
        : 'This goes to the library for review before publication.');

include ROOT_PATH . '/templates/layout/app_header.php';
?>

<div class="results-layout" style="grid-template-columns:minmax(0,1fr) 320px">
  <div>
    <?php if ($errors): ?>
    <div class="alert alert-danger mb-6" role="alert" tabindex="-1" id="errsum">
      <i data-ico="alert" class="ico-sm"></i>
      <div class="grow">
        <strong><?= count($errors) === 1 ? 'One thing needs fixing' : count($errors) . ' things need fixing' ?></strong>
        <ul class="mt-2" style="list-style:disc;padding-left:18px">
          <?php foreach ($errors as $f => $m): ?>
          <li class="small"><a href="#<?= e($f) ?>" style="color:inherit"><?= e($m) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
    <?php endif; ?>

    <form class="card" method="post" enctype="multipart/form-data" novalidate id="depositForm"
          action="<?= e(url('submit.php' . ($editing ? '?id=' . (int)$editing['id'] : ''))) ?>">
      <?php if ($editing): ?><input type="hidden" name="record_id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
      <?= csrfField() ?>

      <fieldset>
        <legend>The work</legend>
        <p class="hint mb-4">This becomes the Dublin Core description — what makes the record findable.</p>

        <div class="field">
          <label class="label" for="dc_title">Title</label>
          <input class="input" id="dc_title" name="dc_title" type="text" required
                 placeholder="The full title as it appears on the title page"
                 <?= isset($errors['dc_title']) ? 'aria-invalid="true"' : '' ?>
                 value="<?= e($in['dc_title']) ?>">
          <?php if (isset($errors['dc_title'])): ?><p class="field-error"><i data-ico="alert" class="ico-sm"></i><span><?= e($errors['dc_title']) ?></span></p><?php endif; ?>
        </div>

        <div class="grid-2">
          <div class="field">
            <label class="label" for="dc_creator">Authors</label>
            <input class="input" id="dc_creator" name="dc_creator" type="text" required
                   placeholder="Dela Cruz, Juan D.; Santos, Maria L."
                   <?= isset($errors['dc_creator']) ? 'aria-invalid="true"' : '' ?>
                   value="<?= e($in['dc_creator']) ?>">
            <span class="hint">Separate names with a semicolon. Depositing student work? Name the
              student here &mdash; the author of the work, not the person filing it.</span>
            <?php if (isset($errors['dc_creator'])): ?><p class="field-error"><i data-ico="alert" class="ico-sm"></i><span><?= e($errors['dc_creator']) ?></span></p><?php endif; ?>
          </div>
          <div class="field">
            <label class="label" for="adviser">Adviser</label>
            <input class="input" id="adviser" name="adviser" type="text"
                   placeholder="Villareal, Ramon C." value="<?= e($in['adviser']) ?>">
            <span class="hint">Optional. One name, credited on the record.</span>
          </div>
          <div class="field">
            <?php
              /* Existing names, then one empty row to type into -- and at least
                 three rows, which is the usual size of a panel. */
              /* The names already on the record, or a single empty box on a new
                 deposit. More boxes are added by the depositor, not by us. */
              $panelRows = array_values(array_filter(array_map('trim', splitList($in['panel'])), static fn($v) => $v !== ''));
              if (!$panelRows) $panelRows = [''];
            ?>
            <span class="label" id="panel-label">Panel members</span>
            <div class="stack-sm" data-panel-rows role="group" aria-labelledby="panel-label">
              <?php foreach ($panelRows as $i => $name): ?>
              <div class="panel-row" data-panel-row>
                <label class="sr-only" for="panel<?= $i ?>">Panel member <?= $i + 1 ?></label>
                <input class="input" id="panel<?= $i ?>" name="panel[]" type="text"
                       placeholder="<?= $i === 0 ? 'Cortez, Elena B.' : 'Another panel member' ?>"
                       value="<?= e($name) ?>">
                <button class="btn btn-ghost btn-icon btn-sm" type="button" data-panel-remove
                        aria-label="Remove panel member <?= $i + 1 ?>"><i data-ico="close" class="ico-sm"></i></button>
              </div>
              <?php endforeach; ?>
            </div>
            <button class="btn btn-ghost btn-sm mt-2" type="button" data-panel-add>
              <i data-ico="plus" class="ico-sm"></i> Add another
            </button>
            <span class="hint">Optional. One name per box.</span>
          </div>
        </div>

        <div class="grid-3">
          <div class="field">
            <label class="label" for="dc_type">Kind of work</label>
            <select class="select" id="dc_type" name="dc_type" required>
              <?php foreach (DOC_TYPES as $t): ?>
              <option value="<?= e($t) ?>" <?= $in['dc_type'] === $t ? 'selected' : '' ?>><?= e($t) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="label" for="year_completed">Year completed</label>
            <input class="input" id="year_completed" name="year_completed" type="number"
                   min="1980" max="<?= (int)date('Y') + 1 ?>" inputmode="numeric"
                   value="<?= e($in['year_completed']) ?>">
          </div>
          <div class="field">
            <label class="label" for="page_count">Pages</label>
            <input class="input" id="page_count" name="page_count" type="number" min="1"
                   inputmode="numeric" placeholder="128" value="<?= e($in['page_count']) ?>">
          </div>
        </div>
      </fieldset>

      <fieldset>
        <legend>What it is about</legend>
        <p class="hint mb-4">The abstract and keywords are how someone finds this in five years.</p>

        <div class="field">
          <label class="label" for="dc_description">Abstract</label>
          <textarea class="textarea" id="dc_description" name="dc_description" rows="7" required
                    placeholder="What problem was studied, how, and what was found."
                    <?= isset($errors['dc_description']) ? 'aria-invalid="true"' : '' ?>><?= e($in['dc_description']) ?></textarea>
          <span class="hint"><span id="wordCount"><?= str_word_count($in['dc_description']) ?></span> words. At least 30.</span>
          <?php if (isset($errors['dc_description'])): ?><p class="field-error"><i data-ico="alert" class="ico-sm"></i><span><?= e($errors['dc_description']) ?></span></p><?php endif; ?>
        </div>

        <div class="field">
          <label class="label" for="dc_subject">Keywords</label>
          <input class="input" id="dc_subject" name="dc_subject" type="text" required
                 placeholder="machine learning; student retention; learning analytics"
                 <?= isset($errors['dc_subject']) ? 'aria-invalid="true"' : '' ?>
                 value="<?= e($in['dc_subject']) ?>">
          <span class="hint">Three to eight, separated by semicolons.</span>
          <div class="chips mt-3" id="kwPreview" aria-live="polite"></div>
          <?php if (isset($errors['dc_subject'])): ?><p class="field-error"><i data-ico="alert" class="ico-sm"></i><span><?= e($errors['dc_subject']) ?></span></p><?php endif; ?>
        </div>

        <div class="grid-3">
          <div class="field">
            <label class="label" for="department_id">Department</label>
            <select class="select" id="department_id" name="department_id" required
                    <?= isset($errors['department_id']) ? 'aria-invalid="true"' : '' ?>>
              <option value="">Choose one</option>
              <?php foreach ($departments as $d): ?>
              <option value="<?= (int)$d['id'] ?>" <?= (string)$in['department_id'] === (string)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (isset($errors['department_id'])): ?><p class="field-error"><i data-ico="alert" class="ico-sm"></i><span><?= e($errors['department_id']) ?></span></p><?php endif; ?>
          </div>
          <div class="field">
            <label class="label" for="dc_language">Language</label>
            <select class="select" id="dc_language" name="dc_language">
              <?php foreach (['English','Filipino','Cebuano','Multiple'] as $l): ?>
              <option value="<?= $l ?>" <?= $in['dc_language'] === $l ? 'selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="label" for="dc_coverage">Where the study applies</label>
            <input class="input" id="dc_coverage" name="dc_coverage" type="text"
                   placeholder="Davao City" value="<?= e($in['dc_coverage']) ?>">
          </div>
        </div>
      </fieldset>

      <fieldset>
        <legend>The file</legend>
        <p class="hint mb-4">Stored, fingerprinted with <?= DIGEST_LABEL ?>, and recorded as a PREMIS object.</p>

        <div class="field">
          <label class="label" for="manuscript">Manuscript</label>
          <?php if ($currentFile): ?>
          <p class="small mb-2">Current file: <strong><?= e($currentFile['original_name']) ?></strong>
            (<?= humanBytes((int)$currentFile['size_bytes']) ?>). Leave this empty to keep it. A new file
            replaces it, and the old one is kept as the previous version.</p>
          <?php endif; ?>
          <input class="input" id="manuscript" name="manuscript" type="file" <?= $editing ? '' : 'required' ?>
                 accept=".pdf,application/pdf" style="padding:10px 14px"
                 <?= isset($errors['manuscript']) ? 'aria-invalid="true"' : '' ?>>
          <div class="file-chosen" id="fileChosen" hidden>
            <i data-ico="file" class="ico-sm"></i>
            <span class="file-chosen-name" id="fileName"></span>
            <span class="file-chosen-size" id="fileSize"></span>
            <button class="btn btn-ghost btn-sm" type="button" id="fileRemove">
              <i data-ico="close" class="ico-sm"></i> Remove file
            </button>
          </div>
          <span class="hint">PDF only, up to <?= humanBytes(MAX_UPLOAD_BYTES) ?>. In Word, use File → Save As → PDF.</span>
          <?php if (isset($errors['manuscript'])): ?><p class="field-error"><i data-ico="alert" class="ico-sm"></i><span><?= e($errors['manuscript']) ?></span></p><?php endif; ?>
        </div>

      </fieldset>

      <fieldset>
        <legend>Who may read it</legend>

        <div class="field">
          <label class="label" for="access_level">Access</label>
          <select class="select" id="access_level" name="access_level" required>
            <?php foreach (ACCESS_LEVELS as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= $in['access_level'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="hint">The description stays public whichever you choose. This controls the file,
            and sets the rights statement recorded with it.</span>
        </div>


      </fieldset>

      <hr class="divider">
      <div class="row-between wrap">
        <p class="small muted" style="max-width:46ch"><?= e($pageSub) ?></p>
        <button class="btn btn-primary btn-lg" type="submit">
          <i data-ico="<?= $editing ? 'check' : 'upload' ?>" class="ico-sm"></i> <?= $editing
              ? ($resend ? 'Save and send for review' : 'Save changes')
              : (isAdmin() ? 'Deposit and publish' : 'Deposit for review') ?>
        </button>
      </div>
    </form>
  </div>

  <!-- ============================= RAIL ============================= -->
  <aside class="stack">
    <div class="card">
      <div class="panel-title mb-4">Description so far</div>
      <div class="row" style="gap:var(--sp-5)">
        <div class="ring-wrap">
          <div class="ring" id="compRing" data-ring="<?= $completeness['percent'] ?>"></div>
          <span class="ring-val" id="compVal"><?= $completeness['percent'] ?>%</span>
        </div>
        <p class="grow small muted" id="compNote">
          <?= $completeness['complete']
              ? 'Everything required is filled in.'
              : count($completeness['missing']) . ' required field'
                . (count($completeness['missing']) === 1 ? '' : 's') . ' still empty.' ?>
        </p>
      </div>
      <hr class="divider">
      <ul class="stack small" id="compList"></ul>
    </div>

    <div class="card">
      <div class="panel-title mb-3">What happens to it</div>
      <div class="timeline">
        <div class="tl-item"><span class="tl-dot ok"></span>
          <div class="list-title">Stored and fingerprinted</div>
          <p class="small muted mt-1">A <?= DIGEST_LABEL ?> digest is taken so any later change is detectable.</p></div>
        <div class="tl-item"><span class="tl-dot gold"></span>
          <div class="list-title"><?= isAdmin() ? 'Published at once' : 'The library reviews' ?></div>
          <p class="small muted mt-1"><?= isAdmin() ? 'Admin deposits skip the queue.' : 'You are notified either way.' ?></p></div>
        <div class="tl-item"><span class="tl-dot"></span>
          <div class="list-title">Identifier minted</div>
          <p class="small muted mt-1">A citable SJP2CD reference is assigned at publication.</p></div>
        <div class="tl-item"><span class="tl-dot"></span>
          <div class="list-title">METS package built</div>
          <p class="small muted mt-1">Description, preservation record and files bound into one XML object.</p></div>
      </div>
    </div>

    <div class="card">
      <div class="panel-title mb-3">Worth knowing</div>
      <ul class="std-list">
        <li><i data-ico="check" class="ico-sm"></i> <span>Deposit the final approved version, not a draft</span></li>
        <li><i data-ico="check" class="ico-sm"></i> <span>Remove signature pages and personal contact details</span></li>
        <li><i data-ico="check" class="ico-sm"></i> <span>Save it as PDF: it looks the same on every computer, for years</span></li>
        <li><i data-ico="check" class="ico-sm"></i> <span>Records are permanent; corrections become new versions</span></li>
      </ul>
      <a class="btn btn-outline btn-sm btn-block mt-6" href="<?= url('standards.php') ?>">Where each field ends up</a>
    </div>
  </aside>
</div>

<?php
$extraFoot = <<<'JS'
<script>
/* The chosen file, with a way to take it back. A native file input can only be
   cleared by picking another file, which is not what someone who attached the
   wrong one wants. Without this script the plain input still works. */
/* Panel members: a row each, added and taken away by the depositor.

   A new row is cloned from an existing one rather than built from strings, so
   it cannot drift from the markup PHP renders. The last row is never removed --
   removing it would leave nothing to type into -- so its button clears the box
   instead. With no script, the rows already on the page still submit. */
document.addEventListener('DOMContentLoaded', function () {
  var rows = document.querySelector('[data-panel-rows]');
  var add  = document.querySelector('[data-panel-add]');
  if (!rows || !add) return;

  function renumber() {
    var all = rows.querySelectorAll('[data-panel-row]');
    all.forEach(function (row, i) {
      var input  = row.querySelector('input');
      var label  = row.querySelector('label');
      var remove = row.querySelector('[data-panel-remove]');
      input.id = 'panel' + i;
      input.placeholder = i === 0 ? 'Cortez, Elena B.' : 'Another panel member';
      if (label) { label.htmlFor = input.id; label.textContent = 'Panel member ' + (i + 1); }
      if (remove) {
        remove.setAttribute('aria-label',
          all.length === 1 ? 'Clear this panel member' : 'Remove panel member ' + (i + 1));
      }
    });
  }

  add.addEventListener('click', function () {
    var last = rows.querySelector('[data-panel-row]:last-of-type');
    var next = last.cloneNode(true);
    next.querySelector('input').value = '';
    rows.appendChild(next);
    renumber();
    next.querySelector('input').focus();
  });

  rows.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-panel-remove]');
    if (!btn) return;
    var row = btn.closest('[data-panel-row]');
    if (rows.querySelectorAll('[data-panel-row]').length > 1) {
      row.remove();
    } else {
      row.querySelector('input').value = '';
      row.querySelector('input').focus();
    }
    renumber();
  });

  renumber();
});

document.addEventListener('DOMContentLoaded', function () {
  var input = document.getElementById('manuscript');
  var card  = document.getElementById('fileChosen');
  if (!input || !card) return;

  var nameEl = document.getElementById('fileName');
  var sizeEl = document.getElementById('fileSize');
  var remove = document.getElementById('fileRemove');

  function human(b) {
    return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
  }

  function show() {
    var f = input.files && input.files[0];
    if (!f) { card.hidden = true; input.hidden = false; return; }
    nameEl.textContent = f.name;
    sizeEl.textContent = human(f.size);
    card.hidden = false;
    input.hidden = true;
  }

  input.addEventListener('change', show);
  remove.addEventListener('click', function () {
    input.value = '';
    input.dispatchEvent(new Event('change', { bubbles: true }));
    input.focus();
  });
  show();
});

document.addEventListener('DOMContentLoaded', function () {
  var sum = document.getElementById('errsum');
  if (sum) sum.focus();

  var form = document.getElementById('depositForm');
  if (!form) return;

  var fields = {
    'dc_title':       'Title',
    'dc_creator':     'Authors',
    'dc_subject':     'Keywords',
    'dc_description': 'Abstract',
    'dc_type':        'Kind of work',
    'dc_language':    'Language'
  };

  function value(id) {
    var el = document.getElementById(id);
    return el ? el.value.trim() : '';
  }

  function update() {
    /* word count */
    var words = value('dc_description') ? value('dc_description').split(/\s+/).filter(Boolean).length : 0;
    var wc = document.getElementById('wordCount');
    if (wc) wc.textContent = String(words);

    /* keyword chips */
    var kws = value('dc_subject').split(';').map(function (s) { return s.trim(); }).filter(Boolean);
    var prev = document.getElementById('kwPreview');
    if (prev) {
      prev.innerHTML = kws.map(function (k) {
        return '<span class="chip chip-static">' + window.Icons.svg('tag', 'ico ico-sm') +
               k.replace(/[<>&]/g, '') + '</span>';
      }).join('');
      prev.querySelectorAll('svg').forEach(function (s) { s.setAttribute('aria-hidden', 'true'); });
    }

    /* completeness */
    var done = 0, total = 0, list = [];
    for (var id in fields) {
      total++;
      var ok = id === 'dc_description' ? words >= 30
             : id === 'dc_subject'     ? kws.length >= 3
             : value(id) !== '';
      if (ok) done++;
      list.push({ label: fields[id], ok: ok });
    }
    var pct = Math.round(done / total * 100);

    var ring = document.getElementById('compRing');
    var val  = document.getElementById('compVal');
    var note = document.getElementById('compNote');
    if (ring) ring.style.setProperty('--p', pct);
    if (val)  val.textContent = pct + '%';
    if (note) note.textContent = done === total
      ? 'Everything required is filled in.'
      : (total - done) + ' required field' + ((total - done) === 1 ? '' : 's') + ' still empty.';

    var ul = document.getElementById('compList');
    if (ul) {
      ul.innerHTML = list.map(function (f) {
        return '<li class="row" style="gap:8px">' +
          window.Icons.svg(f.ok ? 'checkcircle' : 'clock', 'ico ico-sm') +
          '<span class="small" style="color:' + (f.ok ? 'var(--ok)' : 'var(--text-subtle)') + '">' +
          f.label + '</span></li>';
      }).join('');
      ul.querySelectorAll('svg').forEach(function (s) { s.setAttribute('aria-hidden', 'true'); });
    }
  }

  form.addEventListener('input', update);
  form.addEventListener('change', update);
  update();
});
</script>
JS;
include ROOT_PATH . '/templates/layout/app_footer.php';
