<?php
require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/includes/metadata.php';
requireLogin();

if (!canReview()) {
    flash('danger', 'Reviewing is for advisers and library staff.');
    redirect('dashboard.php');
}

$uid   = currentUserId();
$me    = currentUser($pdo);
$stage = isAdmin() ? 'library' : 'adviser';

/** Records this reviewer is allowed to act on. */
function reviewableIds(PDO $pdo, int $uid, bool $admin): array {
    $sql = $admin
        ? "SELECT id FROM records WHERE status IN ('submitted','under_review','approved')"
        : "SELECT id FROM records WHERE adviser_id = ? AND status IN ('submitted','under_review')";
    $s = $pdo->prepare($sql);
    $s->execute($admin ? [] : [$uid]);
    return array_map('intval', array_column($s->fetchAll(), 'id'));
}

/* ---------------------------------------------------------- decisions --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $id       = (int)($_POST['id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $comment  = trim((string)($_POST['comment'] ?? ''));

    if (!in_array($id, reviewableIds($pdo, $uid, isAdmin()), true)) {
        flash('danger', 'That submission is not in your queue.');
        redirect('review.php');
    }
    if (!in_array($decision, ['approve', 'revision', 'reject', 'publish'], true)) {
        flash('danger', 'Pick a decision first.');
        redirect('review.php?id=' . $id);
    }
    if ($decision === 'publish' && !canPublish()) {
        flash('danger', 'Only the library can publish a record.');
        redirect('review.php?id=' . $id);
    }
    if ($decision !== 'approve' && $decision !== 'publish' && $comment === '') {
        flash('warn', 'Say why, so the depositor knows what to change.');
        redirect('review.php?id=' . $id);
    }

    $rs = $pdo->prepare("SELECT * FROM records WHERE id = ?");
    $rs->execute([$id]);
    $rec = $rs->fetch();

    $agent = premisAgentForUser($pdo, $uid, (string)$me['name']);

    try {
        $pdo->beginTransaction();

        $stored = match ($decision) {
            'approve', 'publish' => 'approved',
            'revision'           => 'revision',
            'reject'             => 'rejected',
        };
        $pdo->prepare(
            "INSERT INTO submission_reviews (record_id, reviewer_id, stage, decision, comment)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$id, $uid, $stage, $stored, $comment ?: null]);

        if ($decision === 'publish') {
            $identifier = $rec['dc_identifier'] ?: mintIdentifier($pdo);
            /* dc.date is the Dublin Core date of issue. Publication is what issues a
               record, so it is set here — a published record with an empty dc.date
               is an incomplete Dublin Core description. Only filled if the
               depositor did not already supply one. */
            $pdo->prepare(
                "UPDATE records
                    SET status = 'published', published_at = NOW(), published_by = ?, dc_identifier = ?,
                        dc_date_issued = COALESCE(dc_date_issued, CURDATE())
                  WHERE id = ?"
            )->execute([$uid, $identifier, $id]);

            premisEvent($pdo, $id, 'publication', 'success',
                'Released to the public catalogue as ' . $identifier, $agent);
            buildMetsPackage($pdo, $id);

            notify($pdo, (int)$rec['submitted_by'], 'Your deposit is published',
                   $rec['dc_title'] . ' is now public as ' . $identifier, 'published', $id);
            logActivity($pdo, $uid, 'published a record', $rec['dc_title'], $id);
            flash('ok', 'Published as <strong>' . e($identifier) . '</strong>. It is live in the catalogue now.');

        } elseif ($decision === 'approve') {
            $pdo->prepare("UPDATE records SET status = 'approved' WHERE id = ?")->execute([$id]);
            premisEvent($pdo, $id, 'validation', 'success',
                'Adviser approved the submission', $agent);

            notify($pdo, (int)$rec['submitted_by'], 'Your adviser approved your deposit',
                   $rec['dc_title'] . ' now waits for the library to publish it.', 'approved', $id);

            foreach ($pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll() as $a) {
                notify($pdo, (int)$a['id'], 'A deposit is ready to publish', $rec['dc_title'], 'review', $id);
            }
            logActivity($pdo, $uid, 'approved a submission', $rec['dc_title'], $id);
            flash('ok', 'Approved. The library has been notified.');

        } elseif ($decision === 'revision') {
            $pdo->prepare("UPDATE records SET status = 'revision' WHERE id = ?")->execute([$id]);
            premisEvent($pdo, $id, 'validation', 'fail',
                'Revisions requested: ' . mb_strimwidth($comment, 0, 200, '…'), $agent);
            notify($pdo, (int)$rec['submitted_by'], 'Revisions were requested',
                   mb_strimwidth($comment, 0, 200, '…'), 'revision', $id);
            logActivity($pdo, $uid, 'requested revisions', $rec['dc_title'], $id);
            flash('ok', 'Sent back for revision.');

        } else {
            $pdo->prepare("UPDATE records SET status = 'rejected' WHERE id = ?")->execute([$id]);
            premisEvent($pdo, $id, 'validation', 'fail',
                'Submission returned without approval', $agent);
            notify($pdo, (int)$rec['submitted_by'], 'Your deposit was returned',
                   mb_strimwidth($comment, 0, 200, '…'), 'rejected', $id);
            logActivity($pdo, $uid, 'returned a submission', $rec['dc_title'], $id);
            flash('ok', 'Returned to the depositor.');
        }

        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', 'The decision could not be saved. Nothing was changed.');
    }
    redirect('review.php');
}

/* ------------------------------------------------------- single record --- */
$openId = (int)($_GET['id'] ?? 0);
$open   = null;
if ($openId) {
    $s = $pdo->prepare(
        "SELECT r.*, u.name AS author, u.email AS author_email, u.student_number,
                d.name AS department_name, a.name AS adviser_name
         FROM records r
         JOIN users u        ON u.id = r.submitted_by
         LEFT JOIN departments d ON d.id = r.department_id
         LEFT JOIN users a       ON a.id = r.adviser_id
         WHERE r.id = ?"
    );
    $s->execute([$openId]);
    $open = $s->fetch() ?: null;

    /* An id that matches nothing used to fall through and quietly render the
       queue, which looks like the click did nothing. Say what happened. */
    if (!$open) {
        flash('warn', 'That submission does not exist. It may have been withdrawn or deleted.');
        redirect('review.php');
    }

    if ($open && !in_array($openId, reviewableIds($pdo, $uid, isAdmin()), true)) {
        flash('warn', 'That submission is no longer waiting on you.');
        redirect('review.php');
    }
    if ($open && $open['status'] === 'submitted') {
        $pdo->prepare("UPDATE records SET status = 'under_review' WHERE id = ?")->execute([$openId]);
        $open['status'] = 'under_review';
    }
}

/* ------------------------------------------------------------- queue --- */
if (isAdmin()) {
    $q = $pdo->query(
        "SELECT r.*, u.name AS author, d.name AS department_name,
                (SELECT COUNT(*) FROM record_files f WHERE f.record_id = r.id) file_count
         FROM records r JOIN users u ON u.id = r.submitted_by
         LEFT JOIN departments d ON d.id = r.department_id
         WHERE r.status IN ('approved','under_review','submitted')
         ORDER BY FIELD(r.status,'approved','under_review','submitted'), r.submitted_at ASC"
    );
} else {
    $q = $pdo->prepare(
        "SELECT r.*, u.name AS author, d.name AS department_name,
                (SELECT COUNT(*) FROM record_files f WHERE f.record_id = r.id) file_count
         FROM records r JOIN users u ON u.id = r.submitted_by
         LEFT JOIN departments d ON d.id = r.department_id
         WHERE r.adviser_id = ? AND r.status IN ('submitted','under_review')
         ORDER BY r.submitted_at ASC"
    );
    $q->execute([$uid]);
}
$queue = $q->fetchAll();

$decided = $pdo->prepare(
    "SELECT sr.*, r.dc_title, r.status, u.name AS author
     FROM submission_reviews sr
     JOIN records r ON r.id = sr.record_id
     JOIN users u   ON u.id = r.submitted_by
     WHERE sr.reviewer_id = ? ORDER BY sr.created_at DESC LIMIT 8"
);
$decided->execute([$uid]);
$decidedRows = $decided->fetchAll();

$pageLabel = isAdmin() ? 'Library review' : 'Review queue';
$pageSub   = isAdmin()
    ? 'Open a deposit, check it, and publish it. It becomes public the moment you do.'
    : 'Submissions from the students you advise.';
include ROOT_PATH . '/templates/layout/app_header.php';
?>

<?php if ($open):
  $files = $pdo->prepare("SELECT * FROM record_files WHERE record_id = ? ORDER BY file_use, id");
  $files->execute([$openId]);
  $fileRows = $files->fetchAll();

  $hist = $pdo->prepare(
      "SELECT sr.*, u.name AS reviewer FROM submission_reviews sr
       JOIN users u ON u.id = sr.reviewer_id
       WHERE sr.record_id = ? ORDER BY sr.created_at ASC"
  );
  $hist->execute([$openId]);
  $histRows = $hist->fetchAll();

  $c        = metadataCompleteness($open);
  $canFinal = canPublish();
?>

<a class="btn btn-ghost btn-sm mb-4" href="<?= url('review.php') ?>"><i data-ico="arrowleft" class="ico-sm"></i> Back to the queue</a>

<div class="workspace">
  <div>
    <article class="card mb-6">
      <div class="row wrap mb-3" style="gap:8px">
        <?= statusBadge((string)$open['status']) ?>
        <span class="badge badge-brand"><?= e($open['dc_type']) ?></span>
        <?= accessBadge((string)$open['access_level']) ?>
        <?php if ($open['embargo_until']): ?>
        <span class="badge badge-warn"><i data-ico="clock" class="ico-sm"></i> Embargoed to <?= humanDate($open['embargo_until']) ?></span>
        <?php endif; ?>
      </div>

      <h2 style="font-size:var(--fs-xl)"><?= e($open['dc_title']) ?></h2>
      <p class="muted mt-2"><?= e($open['dc_creator']) ?></p>

      <table class="meta-table mt-6">
        <tr><th scope="row">Deposited by</th><td><?= e($open['author']) ?><?php
            if ($open['student_number']) echo ' <span class="mono xs subtle">' . e($open['student_number']) . '</span>'; ?></td></tr>
        <tr><th scope="row">Department</th><td><?= e($open['department_name'] ?: '—') ?></td></tr>
        <tr><th scope="row">Adviser</th><td><?= e($open['adviser_name'] ?: 'Not assigned') ?></td></tr>
        <tr><th scope="row">Year completed</th><td><?= $open['year_completed'] ? (int)$open['year_completed'] : '—' ?></td></tr>
        <tr><th scope="row">Language</th><td><?= e($open['dc_language'] ?: '—') ?></td></tr>
        <tr><th scope="row">Sent for review</th><td><?= humanDate($open['submitted_at'], 'j F Y, g:i a') ?></td></tr>
      </table>

      <h3 class="panel-title mt-8 mb-2">Abstract</h3>
      <p style="max-width:70ch"><?= nl2br(e($open['dc_description'])) ?></p>

      <?php $kw = splitList($open['dc_subject']); if ($kw): ?>
      <h3 class="panel-title mt-6 mb-2">Keywords</h3>
      <div class="chips">
        <?php foreach ($kw as $k): ?><span class="chip chip-static"><i data-ico="tag" class="ico-sm"></i><?= e($k) ?></span><?php endforeach; ?>
      </div>
      <?php endif; ?>
    </article>

    <section class="card mb-6">
      <h3 class="panel-title mb-4">The deposited files</h3>
      <?php if (!$fileRows): ?>
      <div class="alert alert-warn"><i data-ico="alert" class="ico-sm"></i>
        <span>No file is attached. Do not approve this until the depositor uploads the full text.</span></div>
      <?php else: foreach ($fileRows as $f): $fix = fixityVerdict(verifyFixity($f)); ?>
      <div class="file-row">
        <span class="file-ico"><i data-ico="file"></i></span>
        <span class="grow" style="min-width:0">
          <span class="list-title truncate"><?= e($f['original_name']) ?></span>
          <span class="list-desc"><?= e($f['file_use']) ?> · <?= humanBytes((int)$f['size_bytes']) ?> · <?= e($f['mime_type']) ?></span>
          <span class="xs subtle mono break-any"><?= e($f['checksum_algo']) ?>: <?= e($f['checksum']) ?></span>
        </span>
        <span class="badge <?= $fix['badge'] ?>">
          <i data-ico="<?= $fix['icon'] ?>" class="ico-sm"></i> <?= e($fix['text']) ?>
        </span>
        <a class="btn btn-outline btn-sm" href="<?= url('download.php?file=' . (int)$f['id']) ?>">
          <i data-ico="download" class="ico-sm"></i> Read it
        </a>
      </div>
      <?php endforeach; endif; ?>
    </section>

    <?php if ($histRows): ?>
    <section class="card mb-6">
      <h3 class="panel-title mb-4">Review history</h3>
      <ol class="timeline">
        <?php foreach ($histRows as $h): ?>
        <li class="tl-item">
          <span class="tl-dot" aria-hidden="true"></span>
          <p class="small">
            <span class="strong"><?= e($h['reviewer']) ?></span>
            <?= $h['decision'] === 'approved' ? 'approved this' : ($h['decision'] === 'revision' ? 'asked for revisions' : 'returned this') ?>
            at the <?= e($h['stage']) ?> stage
          </p>
          <?php if ($h['comment']): ?><p class="small muted mt-2">“<?= e($h['comment']) ?>”</p><?php endif; ?>
          <p class="xs subtle mt-2"><?= humanDate($h['created_at'], 'j M Y, g:i a') ?></p>
        </li>
        <?php endforeach; ?>
      </ol>
    </section>
    <?php endif; ?>
  </div>

  <aside class="rail">
    <section class="card">
      <h3 class="panel-title mb-4">Your decision</h3>
      <?php if ($canFinal): ?>
      <p class="small muted mb-4"><?= $open['status'] === 'approved'
          ? 'The adviser has approved this. '
          : '' ?>Publishing mints the permanent identifier and makes the record public straight away.</p>
      <?php else: ?>
      <p class="small muted mb-4">Approving sends this to the library, which publishes it.</p>
      <?php endif; ?>

      <form method="post" id="decide">
        <?= csrfField() ?>
        <input type="hidden" name="id" value="<?= (int)$openId ?>">

        <div class="role-picker mb-4">
          <?php
            $options = $canFinal
              ? [['publish', 'check', 'Publish it', 'Make it public and mint the identifier']]
              : [['approve', 'check', 'Approve', 'Pass it to the library for publication']];
            $options[] = ['revision', 'edit',    'Ask for revisions', 'Send it back with what to fix'];
            $options[] = ['reject',   'xcircle', 'Return it',         'Close it without approval'];
            foreach ($options as $i => [$val, $ico, $label, $desc]):
          ?>
          <label class="role-opt">
            <input type="radio" name="decision" value="<?= $val ?>" <?= $i === 0 ? 'checked' : '' ?> required>
            <span class="role-ico"><i data-ico="<?= $ico ?>"></i></span>
            <span>
              <span class="list-title"><?= e($label) ?></span>
              <span class="list-desc"><?= e($desc) ?></span>
            </span>
          </label>
          <?php endforeach; ?>
        </div>

        <div class="field">
          <label class="label" for="comment">Note to the depositor</label>
          <textarea class="textarea" id="comment" name="comment" rows="5"
                    placeholder="Required when you ask for revisions or return the work."></textarea>
          <p class="hint">They see this on their deposit page and in the notification.</p>
        </div>

        <button class="btn btn-primary btn-block mt-4" type="submit" id="decideBtn"><?= $canFinal ? 'Publish it' : 'Approve' ?></button>
        <script>
        /* The button is named after the choice above it, so it is always
           clear what pressing it will do. */
        (function () {
          var form = document.getElementById('decide');
          var btn  = document.getElementById('decideBtn');
          if (!form || !btn) return;
          var names = { publish: 'Publish it', approve: 'Approve', revision: 'Send back for revisions', reject: 'Return it' };
          form.addEventListener('change', function (ev) {
            if (ev.target.name === 'decision') btn.textContent = names[ev.target.value] || 'Record the decision';
          });
        })();
        </script>
      </form>
    </section>

    <section class="card">
      <h3 class="panel-title mb-4">Metadata check</h3>
      <div class="hbar-row mb-2">
        <span class="hbar-track"><span class="hbar-fill" style="width:<?= (int)$c['percent'] ?>%"></span></span>
        <span class="small strong"><?= (int)$c['percent'] ?>%</span>
      </div>
      <?php if ($c['complete']): ?>
      <p class="small ok"><i data-ico="checkcircle" class="ico-sm"></i> Every required Dublin Core element is present.</p>
      <?php else: ?>
      <p class="small warn"><i data-ico="alert" class="ico-sm"></i>
        Missing: <?= e(implode(', ', array_map('dcElementName', $c['missing']))) ?>.
        Ask for a revision rather than approving an incomplete record.</p>
      <?php endif; ?>
      <a class="btn btn-outline btn-sm btn-block mt-4" href="<?= url('record.php?id=' . (int)$openId) ?>">
        See the full record
      </a>
      <a class="btn btn-ghost btn-sm btn-block mt-2" href="<?= url('mets.php?id=' . (int)$openId) ?>" target="_blank" rel="noopener">
        <i data-ico="layers" class="ico-sm"></i> Inspect the METS
      </a>
    </section>
  </aside>
</div>

<?php else: /* ------------------------------------------------ list view --- */ ?>

<?php if (!$queue): ?>
<div class="card">
  <div class="empty">
    <i data-ico="checkcircle" class="ico-xl"></i>
    <h4>The queue is clear</h4>
    <p class="mt-2" style="max-width:50ch;margin-inline:auto">
      <?= isAdmin()
          ? 'Nothing is waiting on the library. Approved deposits appear here for publication.'
          : 'None of the students you advise have a submission open. New ones arrive here automatically.' ?>
    </p>
  </div>
</div>
<?php else: ?>
<div class="stack">
  <?php foreach ($queue as $r): $c = metadataCompleteness($r); $waited = $r['submitted_at'] ? (int)((time() - strtotime($r['submitted_at'])) / 86400) : 0; ?>
  <article class="card card-hover">
    <div class="row-between wrap mb-3" style="gap:var(--sp-3)">
      <div class="row wrap" style="gap:8px">
        <?= statusBadge((string)$r['status']) ?>
        <span class="badge badge-brand"><?= e($r['dc_type']) ?></span>
        <?php if ($r['department_name']): ?><span class="badge"><?= e($r['department_name']) ?></span><?php endif; ?>
      </div>
      <?php if ($waited >= 7): ?>
      <span class="badge badge-warn"><i data-ico="clock" class="ico-sm"></i> Waiting <?= $waited ?> days</span>
      <?php endif; ?>
    </div>

    <h2 style="font-size:var(--fs-lg)">
      <a href="<?= url('review.php?id=' . (int)$r['id']) ?>"><?= e($r['dc_title']) ?></a>
    </h2>

    <div class="result-meta mt-2">
      <span><i data-ico="user" class="ico-sm"></i> <?= e($r['author']) ?></span>
      <span><i data-ico="file" class="ico-sm"></i> <?= (int)$r['file_count'] ?> file<?= (int)$r['file_count'] === 1 ? '' : 's' ?></span>
      <span><i data-ico="clock" class="ico-sm"></i> Sent <?= timeAgo($r['submitted_at']) ?></span>
      <span><i data-ico="database" class="ico-sm"></i> Metadata <?= (int)$c['percent'] ?>%</span>
    </div>

    <?php if ($r['dc_description']): ?>
    <p class="result-abs clamp-2 mt-3"><?= e(mb_strimwidth((string)$r['dc_description'], 0, 240, '…')) ?></p>
    <?php endif; ?>

    <div class="row wrap mt-4" style="gap:8px">
      <a class="btn btn-primary btn-sm" href="<?= url('review.php?id=' . (int)$r['id']) ?>">
        <?= isAdmin() ? 'Review and publish' : 'Open for review' ?>
      </a>
      <?php if ((int)$r['file_count'] === 0): ?>
      <span class="badge badge-danger"><i data-ico="alert" class="ico-sm"></i> No file attached</span>
      <?php elseif (!$c['complete']): ?>
      <span class="badge badge-warn"><i data-ico="alert" class="ico-sm"></i> Metadata incomplete</span>
      <?php endif; ?>
    </div>
  </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($decidedRows): ?>
<section class="card mt-8">
  <h2 class="panel-title mb-4">Decisions you have made</h2>
  <div class="table-wrap">
    <table class="tbl">
      <caption class="sr-only">Your recent review decisions</caption>
      <thead><tr><th scope="col">Title</th><th scope="col">Depositor</th><th scope="col">You decided</th><th scope="col">Now</th><th scope="col">When</th></tr></thead>
      <tbody>
      <?php foreach ($decidedRows as $d): ?>
      <tr>
        <td><a href="<?= url('record.php?id=' . (int)$d['record_id']) ?>" class="strong"><?= e(mb_strimwidth((string)$d['dc_title'], 0, 60, '…')) ?></a></td>
        <td class="small"><?= e($d['author']) ?></td>
        <td><span class="badge <?= $d['decision'] === 'approved' ? 'badge-ok' : ($d['decision'] === 'rejected' ? 'badge-danger' : 'badge-warn') ?>"><?= e(ucfirst($d['decision'])) ?></span></td>
        <td><?= statusBadge((string)$d['status']) ?></td>
        <td class="small subtle"><?= timeAgo($d['created_at']) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<?php endif; ?>

<?php include ROOT_PATH . '/templates/layout/app_footer.php'; ?>
