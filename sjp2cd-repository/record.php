<?php
require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/includes/metadata.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect('browse.php');

$stmt = $pdo->prepare(
    "SELECT r.*, d.name AS department_name, d.code AS department_code,
            s.name AS submitter_name, a.name AS adviser_name, p.name AS publisher_name
     FROM records r
     LEFT JOIN departments d ON d.id = r.department_id
     LEFT JOIN users s ON s.id = r.submitted_by
     LEFT JOIN users a ON a.id = r.adviser_id
     LEFT JOIN users p ON p.id = r.published_by
     WHERE r.id = ?"
);
$stmt->execute([$id]);
$rec = $stmt->fetch();
if (!$rec) { http_response_code(404); flash('danger', 'That record does not exist.'); redirect('browse.php'); }

/* Unpublished records are visible only to the people involved. */
$maySee = isPublic($rec) || isAdmin()
       || (int)$rec['submitted_by'] === currentUserId()
       || (int)$rec['adviser_id']   === currentUserId();
if (!$maySee) {
    /* A work that was published has been cited. While it is withdrawn for
       revision its address must not break -- it says what is happening
       instead, the way established repositories do. */
    if (!empty($rec['dc_identifier'])) {
        $citeSlug  = static fn(string $style): string => preg_replace('/[^a-z0-9]+/', '', strtolower($style));

$pageTitle = $rec['dc_title'];
        include ROOT_PATH . '/templates/layout/header.php'; ?>
<main id="main">
  <section class="section-sm">
    <div class="container container-narrow">
      <div class="card">
        <span class="badge badge-warn">Temporarily withdrawn</span>
        <h1 class="mt-4" style="font-size:var(--fs-2xl)"><?= e($rec['dc_title']) ?></h1>
        <p class="muted mt-2"><?= e($rec['dc_creator']) ?></p>
        <p class="mt-6">This work is being revised by its author and is not available at the moment.
           It will return at this same address, under the same identifier.</p>
        <p class="small subtle mt-4 mono"><?= e($rec['dc_identifier']) ?></p>
        <a class="btn btn-outline mt-6" href="<?= url('browse.php') ?>">Browse the collection</a>
      </div>
    </div>
  </section>
</main>
<?php   include ROOT_PATH . '/templates/layout/footer.php';
        exit;
    }
    http_response_code(403); flash('danger', 'That record is not published yet.'); redirect('browse.php');
}

/* Count a view once per session */
if (empty($_SESSION['viewed'][$id])) {
    $pdo->prepare("UPDATE records SET views = views + 1 WHERE id = ?")->execute([$id]);
    $_SESSION['viewed'][$id] = true;
}

$files    = $pdo->prepare("SELECT * FROM record_files WHERE record_id = ? ORDER BY file_use, id");
$files->execute([$id]);
$files    = $files->fetchAll();

$objects  = premisObjectsFor($pdo, $id);
$events   = premisEventsFor($pdo, $id);
$mets     = metsPackageOf($pdo, $id);
$dc       = dublinCoreOf($rec);
$keywords = splitList($rec['dc_subject']);

$reviews = $pdo->prepare(
    "SELECT sr.*, u.name AS reviewer_name FROM submission_reviews sr
     LEFT JOIN users u ON u.id = sr.reviewer_id
     WHERE sr.record_id = ? ORDER BY sr.created_at"
);
$reviews->execute([$id]);
$reviews = $reviews->fetchAll();

$related = $pdo->prepare(
    "SELECT id, dc_title, dc_type, dc_date_issued FROM records
     WHERE status = 'published' AND department_id = ? AND id <> ? ORDER BY published_at DESC LIMIT 3"
);
$related->execute([$rec['department_id'], $id]);
$related = $related->fetchAll();

/* The header states the preservation position, so it has to be measured, not
   asserted. This re-hashes the record's own files — one or two small reads. */
$fixity = ['checked' => 0, 'passed' => 0, 'bytes' => 0];
foreach ($files as $f) {
    $fixity['checked']++;
    $fixity['bytes'] += (int)$f['size_bytes'];
    if (verifyFixity($f)['result'] === 'passed') $fixity['passed']++;
}
$fixityOk = $fixity['checked'] > 0 && $fixity['passed'] === $fixity['checked'];

$lastAudit = $pdo->prepare(
    "SELECT a.run_at, fr.result FROM fixity_results fr
     JOIN fixity_audits a ON a.id = fr.audit_id
     WHERE fr.record_id = ? ORDER BY a.run_at DESC LIMIT 1"
);
$lastAudit->execute([$id]);
$lastAudit = $lastAudit->fetch() ?: null;

$canRead   = canReadFullText($rec);
/* ---------------------------------------------------------- citations ---
   Three styles, built from the record rather than typed by hand, so a reader
   can quote this work without transcribing it and introducing a mistake.

   Authors are stored inverted -- "Surname, First M." -- which APA wants as it
   stands, and which MLA wants inverted back for every author after the first.
   Both are derived from the same split, so they cannot drift apart. */
$citeNames = array_values(array_filter(array_map('trim', splitList((string)$rec['dc_creator']))));
$citeYear  = $rec['dc_date_issued']
    ? date('Y', strtotime((string)$rec['dc_date_issued']))
    : ($rec['year_completed'] ?: 'n.d.');
$citeInst  = 'St. John Paul II College of Davao';
$citeId    = (string)($rec['dc_identifier'] ?: '');
$citeUrl   = rtrim(BASE_URL, '/') . '/record.php?id=' . (int)$rec['id'];

/* A name recorded as "Surname, First M." can be rearranged reliably, because
   the comma says where the surname ends. A name typed as "First M. Surname"
   cannot -- "Dela Cruz" would be read as a middle name and a surname -- so it
   is left exactly as the depositor wrote it rather than guessed at. */
$nameParts = static function (string $n): ?array {
    $bits = array_map('trim', explode(',', $n, 2));
    return count($bits) === 2 && $bits[0] !== '' && $bits[1] !== '' ? $bits : null;   /* [surname, given] */
};

/* "Juan D." -> "J. D." */
$initials = static function (string $given): string {
    $out = [];
    foreach (preg_split('/\s+/', trim($given)) as $word) {
        if ($word === '') continue;
        $out[] = mb_strtoupper(mb_substr($word, 0, 1)) . '.';
    }
    return implode(' ', $out);
};

/* APA 7 abbreviates given names: "Dela Cruz, Juan D." -> "Dela Cruz, J. D." */
$apaName = static function (string $n) use ($nameParts, $initials): string {
    $p = $nameParts($n);
    return $p ? $p[0] . ', ' . $initials($p[1]) : $n;
};

/* IEEE puts the initials first: "Dela Cruz, Juan D." -> "J. D. Dela Cruz" */
$ieeeName = static function (string $n) use ($nameParts, $initials): string {
    $p = $nameParts($n);
    return $p ? $initials($p[1]) . ' ' . $p[0] : $n;
};

$uninvert = static function (string $n) use ($nameParts): string {
    $p = $nameParts($n);
    return $p ? $p[1] . ' ' . $p[0] : $n;
};

$apaList = array_map($apaName, $citeNames);
$apaNames = match (count($apaList)) {
    0       => $citeInst,
    1       => $apaList[0],
    2       => $apaList[0] . ', & ' . $apaList[1],
    default => implode(', ', array_slice($apaList, 0, -1)) . ', & ' . end($apaList),
};

$ieeeList = array_map($ieeeName, $citeNames);
$ieeeNames = match (count($ieeeList)) {
    0       => $citeInst,
    1       => $ieeeList[0],
    2       => $ieeeList[0] . ' and ' . $ieeeList[1],
    default => implode(', ', array_slice($ieeeList, 0, -1)) . ', and ' . end($ieeeList),
};

$mlaNames = match (count($citeNames)) {
    0       => $citeInst,
    1       => $citeNames[0],
    2       => $citeNames[0] . ', and ' . $uninvert($citeNames[1]),
    default => $citeNames[0] . ', et al.',
};

/* The address a reader would actually open. The host is taken from the
   request, so a citation copied from this page always points back to wherever
   the repository is running. */
$citeHost = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
          . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$citeLink = $citeHost . $citeUrl;
$citeRepo = 'SJP2CD Institutional Repository';

$citations = [
    /* APA 7th: the type and institution sit together in the brackets, and the
       repository is named as the source, which is what the 7th edition asks
       for when a work is held in one. */
    'APA 7' => sprintf('%s (%s). %s [%s, %s]. %s. %s',
        $apaNames, $citeYear, $rec['dc_title'], $rec['dc_type'], $citeInst, $citeRepo, $citeLink),

    'MLA' => sprintf('%s. "%s." %s, %s, %s.',
        $mlaNames, $rec['dc_title'], $citeInst, $citeYear, $citeLink),

    'IEEE' => sprintf('%s, "%s," %s, %s, Davao City, Philippines, %s. [Online]. Available: %s',
        $ieeeNames, $rec['dc_title'], mb_strtolower((string)$rec['dc_type']), $citeInst, $citeYear, $citeLink),

    'BibTeX' => sprintf(
        "@mastersthesis{%s,
  title     = {%s},
  author    = {%s},
  year      = {%s},
  school    = {%s},
  type      = {%s},
  note      = {%s},
  url       = {%s}
}",
        $citeId ?: ('sjp2cd' . (int)$rec['id']),
        $rec['dc_title'],
        implode(' and ', $citeNames ?: [$citeInst]),
        $citeYear === 'n.d.' ? '' : $citeYear,
        $citeInst,
        $rec['dc_type'],
        $citeId,
        $citeLink),
];

/* Kept for anything still referring to the old single-string citation. */
$citation = $citations['APA 7'];

$citeSlug  = static fn(string $style): string => preg_replace('/[^a-z0-9]+/', '', strtolower($style));

$pageTitle = $rec['dc_title'];
$navActive = 'browse';
include ROOT_PATH . '/templates/layout/header.php';
?>

<main id="main" class="section-sm">
  <div class="container">

    <nav aria-label="Breadcrumb" class="mb-4">
      <ol class="row small subtle wrap" style="gap:8px">
        <li><a href="<?= url('index.php') ?>">Home</a></li>
        <li aria-hidden="true">/</li>
        <li><a href="<?= url('browse.php') ?>">Browse</a></li>
        <li aria-hidden="true">/</li>
        <li aria-current="page" class="mono"><?= e($rec['dc_identifier'] ?: 'unpublished') ?></li>
      </ol>
    </nav>

    <?php if (!isPublic($rec)): ?>
    <div class="alert alert-warn mb-6">
      <i data-ico="eye" class="ico-sm"></i>
      <span><strong>Not published yet.</strong>This is <?= e(strtolower(statusLabel((string)$rec['status']))) ?>.
      Only you, the adviser and the library can see it.</span>
    </div>
    <?php endif; ?>

    <!-- The record hero. It leads with the two facts that are hardest to argue
         with: the permanent identifier, and whether the bytes still verify. -->
    <header class="rec-hero">
      <div class="rec-hero-top">
        <div class="row wrap" style="gap:8px">
          <span class="badge badge-brand badge-lg"><?= e($rec['dc_type']) ?></span>
          <?php if ($rec['department_name']): ?><span class="badge badge-lg"><?= e($rec['department_name']) ?></span><?php endif; ?>
          <?= accessBadge((string)$rec['access_level']) ?>
          <?php if (!isPublic($rec)): ?><?= statusBadge((string)$rec['status']) ?><?php endif; ?>
        </div>
        <?php if ($rec['dc_identifier']): ?>
        <span class="rec-id mono" title="Permanent repository identifier"><?= e($rec['dc_identifier']) ?></span>
        <?php endif; ?>
      </div>

      <h1 class="rec-title"><?= e($rec['dc_title']) ?></h1>

      <p class="rec-authors"><?= e($rec['dc_creator']) ?></p>

      <div class="rec-facts">
        <?php if ($rec['dc_contributor']): ?>
        <span><i data-ico="cap" class="ico-sm"></i> <?= e($rec['dc_contributor']) ?></span>
        <?php endif; ?>
        <span><i data-ico="calendar" class="ico-sm"></i> <?= $rec['dc_date_issued'] ? humanDate($rec['dc_date_issued']) : ($rec['year_completed'] ?: 'Undated') ?></span>
        <span><i data-ico="eye" class="ico-sm"></i> <?= number_format((int)$rec['views']) ?> views</span>
        <span><i data-ico="download" class="ico-sm"></i> <?= number_format((int)$rec['downloads']) ?> downloads</span>
        <?php if ($rec['page_count']): ?><span><i data-ico="file" class="ico-sm"></i> <?= (int)$rec['page_count'] ?> pages</span><?php endif; ?>
      </div>

      <?php if ($fixity['checked'] > 0): ?>
      <!-- Measured at page load, not asserted. -->
      <div class="rec-fixity <?= $fixityOk ? 'is-ok' : 'is-bad' ?>">
        <span class="rec-fixity-ico"><i data-ico="<?= $fixityOk ? 'shieldcheck' : 'alert' ?>"></i></span>
        <span>
          <span class="list-title">
            <?= $fixityOk
                ? 'Integrity verified just now'
                : 'Integrity check failed on ' . ($fixity['checked'] - $fixity['passed']) . ' of ' . $fixity['checked'] . ' file(s)' ?>
          </span>
          <span class="list-desc">
            <?= (int)$fixity['checked'] ?> file<?= $fixity['checked'] === 1 ? '' : 's' ?>,
            <?= humanBytes((int)$fixity['bytes']) ?>, re-hashed with <?= DIGEST_LABEL ?><?php
            if ($lastAudit) echo ' · last formal audit ' . timeAgo($lastAudit['run_at']); ?>
          </span>
        </span>
      </div>
      <?php endif; ?>

      <div class="rec-actions">
          <?php if ($files && $canRead): ?>
          <a class="btn btn-primary" href="<?= url('download.php?id=' . $id) ?>"><i data-ico="download" class="ico-sm"></i> Download the file</a>
          <?php elseif ($files && !isLoggedIn() && in_array($rec['access_level'], ['open','campus'], true) && empty($rec['embargo_until'])): ?>
          <!-- Open or campus access, and the reader simply has no account yet.
               Say that, rather than "restricted", which contradicts the badge.
               Only shown where signing in will actually grant the file — a
               genuinely restricted record must not promise access. -->
          <a class="btn btn-primary" href="<?= url('download.php?id=' . $id) ?>"><i data-ico="lock" class="ico-sm"></i> Sign in to download</a>
          <?php elseif ($files && isGuest()): ?>
          <button class="btn btn-outline" type="button" disabled aria-disabled="true"><i data-ico="lock" class="ico-sm"></i> Reading only</button>
          <span class="small muted">Files are for college accounts. Everything else on this page is yours to read.</span>
          <?php elseif ($files): ?>
          <button class="btn btn-outline" type="button" disabled aria-disabled="true"><i data-ico="lock" class="ico-sm"></i> Full text restricted</button>
          <?php endif; ?>
          <button class="btn btn-outline" type="button" data-copy="#cite-apa7"><i data-ico="quote" class="ico-sm"></i> Copy citation</button>
          <a class="btn btn-outline" href="<?= url('mets.php?id=' . $id . '&download=1') ?>"><i data-ico="layers" class="ico-sm"></i> Download METS</a>
          <?php if (canEditRecord($rec)): ?>
          <a class="btn btn-ghost" href="<?= url('submit.php?id=' . $id) ?>"><i data-ico="edit" class="ico-sm"></i> Edit</a>
          <?php endif; ?>
      </div>
    </header>

    <div class="record-layout">
      <div>
        <div class="tabs" role="tablist" aria-label="Record views">
          <button class="tab" role="tab" id="t-ov" aria-controls="p-ov" aria-selected="true">Overview</button>
          <button class="tab" role="tab" id="t-dc" aria-controls="p-dc" aria-selected="false" tabindex="-1">Dublin Core</button>
          <button class="tab" role="tab" id="t-pr" aria-controls="p-pr" aria-selected="false" tabindex="-1">PREMIS</button>
          <button class="tab" role="tab" id="t-mt" aria-controls="p-mt" aria-selected="false" tabindex="-1">METS</button>
          <button class="tab" role="tab" id="t-fl" aria-controls="p-fl" aria-selected="false" tabindex="-1">Files</button>
        </div>

        <!-- Overview -->
        <section class="card" role="tabpanel" id="p-ov" aria-labelledby="t-ov" tabindex="0">
          <h3>Abstract</h3>
          <p class="muted mt-3" style="max-width:74ch"><?= nl2br(e($rec['dc_description'] ?: 'No abstract was supplied.')) ?></p>

          <?php if ($keywords): ?>
          <hr class="divider">
          <h4 class="mb-3">Keywords</h4>
          <div class="chips">
            <?php foreach ($keywords as $k): ?>
            <a class="chip" href="<?= url('browse.php?subject=' . urlencode($k)) ?>"><i data-ico="tag" class="ico-sm"></i><?= e($k) ?></a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <?php if ($reviews): ?>
          <hr class="divider">
          <h4 class="mb-4">Review history</h4>
          <div class="timeline">
            <?php foreach ($reviews as $rv): ?>
            <div class="tl-item">
              <span class="tl-dot <?= $rv['decision'] === 'approved' ? 'ok' : 'warn' ?>"></span>
              <div class="row-between wrap" style="gap:8px">
                <span class="list-title"><?= e(ucfirst($rv['stage'])) ?> — <?= e($rv['decision']) ?></span>
                <span class="list-time"><?= humanDate($rv['created_at'], 'j M Y') ?></span>
              </div>
              <?php if ($rv['comment']): ?><p class="small muted mt-2"><?= nl2br(e($rv['comment'])) ?></p><?php endif; ?>
              <p class="xs subtle mt-1"><?= e($rv['reviewer_name'] ?: 'Repository') ?></p>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </section>

        <!-- Dublin Core -->
        <section class="card" role="tabpanel" id="p-dc" aria-labelledby="t-dc" tabindex="0" hidden>
          <div class="panel-head">
            <div>
              <div class="panel-title">Dublin Core</div>
              <p class="small muted mt-2">What makes this record findable.</p>
            </div>
            <span class="badge badge-brand"><i data-ico="database"></i> <?= count($dc) ?> elements</span>
          </div>
          <table class="meta-table"><tbody>
            <?php foreach ($dc as $el => $val): ?>
            <tr><th scope="row"><?= e($el) ?></th><td><?= e($val) ?></td></tr>
            <?php endforeach; ?>
          </tbody></table>
        </section>

        <!-- PREMIS -->
        <section class="card" role="tabpanel" id="p-pr" aria-labelledby="t-pr" tabindex="0" hidden>
          <div class="panel-head">
            <div>
              <div class="panel-title">PREMIS</div>
              <p class="small muted mt-2">What the file is, and everything that has happened to it.</p>
            </div>
            <span class="badge badge-ok"><i data-ico="shieldcheck"></i> <?= DIGEST_LABEL ?></span>
          </div>

          <?php if ($objects): foreach ($objects as $o): ?>
          <h4 class="mt-4 mb-3">Object</h4>
          <table class="meta-table"><tbody>
            <tr><th scope="row">objectIdentifier</th><td class="mono break-any"><?= e($o['object_identifier']) ?></td></tr>
            <tr><th scope="row">format</th><td><?= e($o['format_name']) ?></td></tr>
            <tr><th scope="row">formatRegistry</th><td class="mono"><?= e($o['format_registry']) ?></td></tr>
            <tr><th scope="row">size</th><td class="num"><?= number_format((int)$o['size_bytes']) ?> bytes</td></tr>
            <tr><th scope="row">messageDigestAlgorithm</th><td><?= e($o['digest_algorithm']) ?></td></tr>
            <tr><th scope="row">messageDigest</th><td class="mono break-any" style="font-size:11px"><?= e($o['message_digest']) ?></td></tr>
            <tr><th scope="row">preservationLevel</th><td><?= e($o['preservation_level']) ?></td></tr>
          </tbody></table>
          <?php endforeach; else: ?>
          <div class="empty"><i data-ico="shield" class="ico-xl"></i><h4>No preservation record</h4></div>
          <?php endif; ?>

          <?php if ($events): ?>
          <h4 class="mt-8 mb-4">Events</h4>
          <div class="timeline">
            <?php foreach ($events as $ev): ?>
            <div class="tl-item">
              <span class="tl-dot <?= $ev['outcome'] === 'success' ? 'ok' : 'warn' ?>"></span>
              <div class="row-between wrap" style="gap:8px">
                <span class="list-title" style="text-transform:capitalize"><?= e($ev['event_type']) ?></span>
                <span class="badge <?= $ev['outcome'] === 'success' ? 'badge-ok' : 'badge-warn' ?>"><?= e($ev['outcome']) ?></span>
              </div>
              <p class="small muted mt-2"><?= e($ev['outcome_detail']) ?></p>
              <p class="xs subtle mt-1"><span class="mono"><?= e($ev['event_datetime']) ?></span> — <?= e($ev['agent_name'] ?: 'repository') ?></p>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </section>

        <!-- METS -->
        <section class="card" role="tabpanel" id="p-mt" aria-labelledby="t-mt" tabindex="0" hidden>
          <div class="panel-head">
            <div>
              <div class="panel-title">METS</div>
              <p class="small muted mt-2">The wrapper binding the description and the preservation record to the files.</p>
            </div>
            <span class="badge badge-violet"><i data-ico="layers"></i> ETD profile</span>
          </div>

          <?php if ($mets): ?>
          <table class="meta-table"><tbody>
            <tr><th scope="row">OBJID</th><td class="mono"><?= e($mets['package']['objid']) ?></td></tr>
            <tr><th scope="row">PROFILE</th><td class="mono break-any"><?= e($mets['package']['profile']) ?></td></tr>
            <tr><th scope="row">CREATEDATE</th><td class="mono"><?= e($mets['package']['create_date']) ?></td></tr>
            <tr><th scope="row">Agent</th><td><?= e($mets['package']['agent_name']) ?></td></tr>
          </tbody></table>

          <?php if ($mets['divisions']): ?>
          <h4 class="mt-8 mb-3">Structural map</h4>
          <div class="table-wrap">
            <table class="tbl">
              <thead><tr><th scope="col">Order</th><th scope="col">Division</th><th scope="col">Points at</th></tr></thead>
              <tbody>
                <?php foreach ($mets['divisions'] as $d): ?>
                <tr>
                  <td class="mono"><?= (int)$d['div_order'] + 1 ?></td>
                  <td class="strong"><?= e($d['div_label']) ?></td>
                  <td class="mono xs"><?= e($d['file_pointer'] ?: '—') ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="hint mt-3">This is what lets a reader jump straight to a chapter, and what lets another system rebuild the document's shape.</p>
          <?php endif; ?>

          <div class="row wrap mt-8" style="gap:var(--sp-2)">
            <a class="btn btn-primary btn-sm" href="<?= url('mets.php?id=' . $id) ?>" target="_blank" rel="noopener"><i data-ico="code" class="ico-sm"></i> View the XML</a>
            <a class="btn btn-outline btn-sm" href="<?= url('mets.php?id=' . $id . '&download=1') ?>"><i data-ico="download" class="ico-sm"></i> Download package</a>
          </div>
          <?php else: ?>
          <div class="empty"><i data-ico="layers" class="ico-xl"></i><h4>No package built yet</h4></div>
          <?php endif; ?>
        </section>

        <!-- Files -->
        <section class="card" role="tabpanel" id="p-fl" aria-labelledby="t-fl" tabindex="0" hidden>
          <div class="panel-head">
            <div class="panel-title">Files</div>
            <span class="badge"><?= count($files) ?></span>
          </div>
          <?php if (!$files): ?>
          <div class="empty"><i data-ico="file" class="ico-xl"></i><h4>No file attached</h4></div>
          <?php else: foreach ($files as $f): ?>
          <div class="file-row">
            <span class="file-ico"><i data-ico="file" class="ico-sm"></i></span>
            <div class="grow">
              <div class="list-title break-any"><?= e($f['original_name']) ?>
                <?php if (!empty($f['superseded_at'])): ?><span class="badge">Previous version</span><?php endif; ?></div>
              <div class="xs subtle">
                <?= e($f['file_use']) ?> · <?= e($f['mime_type']) ?> · <?= humanBytes((int)$f['size_bytes']) ?>
                · <?= e($f['checksum_algo']) ?> recorded
              </div>
            </div>
            <?php if ($canRead && empty($f['superseded_at'])): ?>
            <a class="btn btn-outline btn-sm" href="<?= url('download.php?id=' . $id) ?>"><i data-ico="download" class="ico-sm"></i></a>
            <?php endif; ?>
          </div>
          <?php endforeach; endif; ?>

          <?php if (!$canRead && $files): ?>
          <div class="alert alert-warn mt-6">
            <i data-ico="lock" class="ico-sm"></i>
            <span><strong><?= e(ACCESS_LEVELS[$rec['access_level']] ?? '') ?></strong>
            The description above stays public. To read the file, sign in with a college account or ask the library.</span>
          </div>
          <?php endif; ?>
        </section>
      </div>

      <!-- ============================ RAIL ============================ -->
      <aside class="stack">
        <div class="card">
          <div class="panel-title mb-4">This record</div>
          <div class="stat-row" style="grid-template-columns:1fr 1fr">
            <div class="stat stat-blue" style="min-height:96px;padding:var(--sp-4)">
              <span class="stat-label">Downloads</span>
              <span class="stat-value" style="font-size:var(--fs-2xl)"><?= number_format((int)$rec['downloads']) ?></span>
            </div>
            <div class="stat stat-gold" style="min-height:96px;padding:var(--sp-4)">
              <span class="stat-label">Views</span>
              <span class="stat-value" style="font-size:var(--fs-2xl)"><?= number_format((int)$rec['views']) ?></span>
            </div>
          </div>
          <hr class="divider">
          <dl class="stack small">
            <div class="row-between"><dt class="subtle">Identifier</dt><dd class="mono"><?= e($rec['dc_identifier'] ?: '—') ?></dd></div>
            <div class="row-between"><dt class="subtle">Deposited by</dt><dd class="strong"><?= e($rec['submitter_name']) ?></dd></div>
            <?php /* The adviser credited on the work, falling back to the account that
                     reviewed it for records deposited before the two were separated. */
                  $adviser = $rec['adviser'] ?: $rec['adviser_name']; ?>
            <?php if ($adviser): ?>
            <div class="row-between"><dt class="subtle">Adviser</dt><dd><?= e($adviser) ?></dd></div>
            <?php endif; ?>
            <?php if (!empty($rec['panel'])): ?>
            <div class="row-between"><dt class="subtle">Panel</dt>
              <dd style="text-align:right"><?= e(implode(', ', splitList((string)$rec['panel']))) ?></dd></div>
            <?php endif; ?>
            <div class="row-between"><dt class="subtle">Language</dt><dd><?= e($rec['dc_language']) ?></dd></div>
            <?php if ($rec['page_count']): ?>
            <div class="row-between"><dt class="subtle">Pages</dt><dd class="num"><?= (int)$rec['page_count'] ?></dd></div>
            <?php endif; ?>
          </dl>
        </div>

        <div class="card" data-cite>
          <div class="panel-head">
            <div class="panel-title">Cite this</div>
            <button class="btn btn-ghost btn-sm btn-icon" type="button" data-copy="#cite-apa7"
                    data-cite-copy aria-label="Copy citation"><i data-ico="copy" class="ico-sm"></i></button>
          </div>

          <div class="chips mb-3" role="tablist" aria-label="Citation style">
            <?php $first = true; foreach ($citations as $style => $text): ?>
            <button class="chip<?= $first ? ' is-active' : '' ?>" type="button" role="tab"
                    aria-selected="<?= $first ? 'true' : 'false' ?>"
                    aria-controls="cite-<?= $citeSlug($style) ?>"
                    data-cite-style="<?= $citeSlug($style) ?>"><?= e($style) ?></button>
            <?php $first = false; endforeach; ?>
          </div>

          <?php $first = true; foreach ($citations as $style => $text): ?>
          <p class="small cite-text<?= $style === 'BibTeX' ? ' mono' : '' ?>"
             id="cite-<?= $citeSlug($style) ?>" role="tabpanel"
             <?= $first ? '' : 'hidden' ?>><?= e($text) ?></p>
          <?php $first = false; endforeach; ?>
        </div>

        <div class="card">
          <div class="panel-title mb-4">Metadata layers</div>
          <div class="stack">
            <div class="row-between"><span class="badge badge-brand"><i data-ico="database"></i> Dublin Core</span><span class="small muted"><?= count($dc) ?> elements</span></div>
            <div class="row-between"><span class="badge badge-ok"><i data-ico="shield"></i> PREMIS</span><span class="small muted"><?= count($events) ?> events</span></div>
            <div class="row-between"><span class="badge badge-violet"><i data-ico="layers"></i> METS</span><span class="small muted"><?= $mets ? count($mets['divisions']) . ' divisions' : 'none' ?></span></div>
          </div>
          <a class="btn btn-outline btn-sm btn-block mt-6" href="<?= url('standards.php') ?>">Why three standards</a>
        </div>

        <?php if ($related): ?>
        <div class="card">
          <div class="panel-title mb-3">Also from <?= e($rec['department_code'] ?: 'this department') ?></div>
          <?php foreach ($related as $r): ?>
          <a class="list-item" href="<?= url('record.php?id=' . (int)$r['id']) ?>">
            <span class="file-ico" style="background:var(--primary-soft);color:var(--primary)"><i data-ico="file" class="ico-sm"></i></span>
            <span class="grow">
              <span class="list-title clamp-2" style="display:-webkit-box"><?= e($r['dc_title']) ?></span>
              <span class="xs subtle"><?= $r['dc_date_issued'] ? date('Y', strtotime((string)$r['dc_date_issued'])) : '' ?> · <?= e($r['dc_type']) ?></span>
            </span>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </aside>
    </div>
  </div>
</main>

<?php include ROOT_PATH . '/templates/layout/footer.php'; ?>
