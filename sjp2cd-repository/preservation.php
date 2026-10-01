<?php
require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/includes/metadata.php';
require_once ROOT_PATH . '/includes/maintenance.php';

/* ------------------------------------------------------- run an audit --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (!canPublish()) {
        flash('danger', 'Only library staff can run a fixity audit.');
        redirect('preservation.php');
    }

    /* The audit itself lives in includes/maintenance.php, so a scheduled run and
       a librarian's click do exactly the same work. */
    try {
        $agent = premisAgentForUser($pdo, currentUserId(), (string)currentUser($pdo)['name']);
        $a = runFixityAudit($pdo, currentUserId(), $agent);
        [$failed, $unverifiable, $checked] = [$a['failed'], $a['unverifiable'], $a['checked']];

        logActivity($pdo, currentUserId(), 'ran a fixity audit',
            $checked . ' objects checked, ' . $failed . ' failed');

        if ($failed > 0) {
            flash('danger', $failed . ' object' . ($failed === 1 ? '' : 's') . ' failed the checksum comparison. Look at the results below.');
        } elseif ($unverifiable > 0) {
            flash('warn', 'All comparable objects passed, but ' . $unverifiable . ' could not be verified.');
        } else {
            flash('ok', 'All ' . $checked . ' object' . ($checked === 1 ? '' : 's') . ' passed. Every stored file still matches its ' . DIGEST_LABEL . ' digest.');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('danger', 'The audit could not be completed, so nothing was recorded.');
    }
    redirect('preservation.php');
}

/* ---------------------------------------------------------- the report --- */
$stats = $pdo->query(
    "SELECT
      (SELECT COUNT(*) FROM record_files)                                    files,
      (SELECT COALESCE(SUM(size_bytes),0) FROM record_files)                 bytes,
      (SELECT COUNT(*) FROM record_files WHERE checksum IS NULL OR checksum='') no_digest,
      (SELECT COUNT(*) FROM premis_objects)                                  objects,
      (SELECT COUNT(*) FROM premis_events)                                   events,
      (SELECT COUNT(*) FROM premis_events WHERE event_type='fixity check')   fixity_events,
      (SELECT COUNT(*) FROM mets_packages)                                   packages,
      (SELECT COUNT(*) FROM records WHERE status='published')                published"
)->fetch();

$audits = $pdo->query(
    "SELECT a.*, u.name AS operator FROM fixity_audits a
     LEFT JOIN users u ON u.id = a.run_by ORDER BY a.run_at DESC LIMIT 10"
)->fetchAll();
$last = $audits[0] ?? null;

$problems = [];
if ($last) {
    $p = $pdo->prepare(
        "SELECT fr.*, f.original_name, r.dc_title, r.dc_identifier
         FROM fixity_results fr
         JOIN record_files f ON f.id = fr.file_id
         JOIN records r      ON r.id = fr.record_id
         WHERE fr.audit_id = ? AND fr.result <> 'passed'
         ORDER BY FIELD(fr.result,'failed','missing_file','no_digest')"
    );
    $p->execute([(int)$last['id']]);
    $problems = $p->fetchAll();
}

$formats = $pdo->query(
    "SELECT mime_type, COUNT(*) n, COALESCE(SUM(size_bytes),0) bytes
     FROM record_files GROUP BY mime_type ORDER BY n DESC"
)->fetchAll();

$oldest = $pdo->query("SELECT MIN(created_at) FROM record_files")->fetchColumn();

$health = $stats['files'] > 0 && $last
    ? (int)round(((int)$last['passed'] / max(1, (int)$last['objects_checked'])) * 100)
    : ($stats['files'] > 0 ? 0 : 100);

$pageTitle = 'Preservation';
$navActive = 'preservation';
include ROOT_PATH . '/templates/layout/header.php';
?>

<main id="main">

<section class="section-sm">
  <div class="container">
    <nav aria-label="Breadcrumb" class="mb-4">
      <ol class="row small subtle" style="gap:8px">
        <li><a href="<?= url('index.php') ?>">Home</a></li>
        <li aria-hidden="true">/</li>
        <li aria-current="page">Preservation</li>
      </ol>
    </nav>

    <div class="row-between wrap" style="gap:var(--sp-6)">
      <div class="container-narrow" style="margin-inline:0;padding:0">
        <h1 style="font-size:var(--fs-3xl)">Keeping the files readable</h1>
        <p class="hero-lede mt-4">
          Storing a PDF is not preservation. Preservation is knowing, at any moment, that the bytes you stored
          are the bytes you still have — and being able to prove it. Every file here carries a
          <?= DIGEST_LABEL ?> digest computed the moment it arrived, and an audit can recompute all of them on demand.
        </p>
      </div>

      <?php if (canPublish()): ?>
      <form method="post" class="card card-tight" style="min-width:260px">
        <?= csrfField() ?>
        <p class="label mb-2">Integrity audit</p>
        <p class="small muted mb-4">Re-hashes every stored file and compares it against the digest on record.</p>
        <button class="btn btn-primary btn-block" type="submit">
          <i data-ico="refresh" class="ico-sm"></i> Run a fixity audit
        </button>
        <p class="xs subtle mt-3">
          <?= $last ? 'Last run ' . timeAgo($last['run_at']) : 'Never run on this repository' ?>
        </p>
      </form>
      <?php endif; ?>
    </div>

    <div class="stat-row mt-8">
      <article data-tilt class="stat <?= $health === 100 ? 'stat-mint' : 'stat-gold' ?>">
        <span class="stat-ico"><i data-ico="shieldcheck"></i></span>
        <span class="stat-value"><?= $last ? $health . '%' : '—' ?></span>
        <span class="stat-label">Objects passing fixity</span>
        <span class="stat-foot"><?= $last
            ? number_format((int)$last['passed']) . ' of ' . number_format((int)$last['objects_checked']) . ' at the last audit'
            : 'Run an audit to establish a baseline' ?></span>
      </article>
      <article data-tilt class="stat stat-blue">
        <span class="stat-ico"><i data-ico="archive"></i></span>
        <span class="stat-value"><?= number_format((int)$stats['files']) ?></span>
        <span class="stat-label">Files under management</span>
        <span class="stat-foot"><?= humanBytes((int)$stats['bytes']) ?> stored</span>
      </article>
      <article data-tilt class="stat stat-lilac">
        <span class="stat-ico"><i data-ico="history"></i></span>
        <span class="stat-value"><?= number_format((int)$stats['events']) ?></span>
        <span class="stat-label">Preservation events</span>
        <span class="stat-foot"><?= number_format((int)$stats['fixity_events']) ?> of them fixity checks</span>
      </article>
      <article data-tilt class="stat stat-gold">
        <span class="stat-ico"><i data-ico="fingerprint"></i></span>
        <span class="stat-value"><?= (int)$stats['no_digest'] === 0 ? '100%' : '—' ?></span>
        <span class="stat-label">Files with a digest</span>
        <span class="stat-foot"><?= (int)$stats['no_digest'] === 0
            ? 'Every file carries a ' . DIGEST_LABEL . ' checksum'
            : number_format((int)$stats['no_digest']) . ' file(s) missing a digest' ?></span>
      </article>
    </div>
  </div>
</section>

<?php if ($problems): ?>
<section class="section-sm">
  <div class="container">
    <div class="card" style="border-color:var(--danger)">
      <h2 class="panel-title mb-2" style="color:var(--danger)">
        <i data-ico="alert" class="ico-sm"></i> Objects that did not verify
      </h2>
      <p class="small muted mb-4">From the audit run <?= timeAgo($last['run_at']) ?>. Restore these from backup and re-run the audit.</p>
      <div class="table-wrap">
        <table class="tbl">
          <caption class="sr-only">Files that failed the most recent fixity audit</caption>
          <thead><tr><th scope="col">Record</th><th scope="col">File</th><th scope="col">Result</th><th scope="col">Expected</th><th scope="col">Found</th></tr></thead>
          <tbody>
          <?php foreach ($problems as $p): ?>
          <tr>
            <td><a href="<?= url('record.php?id=' . (int)$p['record_id']) ?>" class="strong"><?= e(mb_strimwidth((string)$p['dc_title'], 0, 44, '…')) ?></a></td>
            <td class="small"><?= e($p['original_name']) ?></td>
            <td><span class="badge badge-danger"><?= e(str_replace('_', ' ', (string)$p['result'])) ?></span></td>
            <td class="mono xs break-any"><?= e(substr((string)$p['expected_digest'], 0, 24) ?: '—') ?>…</td>
            <td class="mono xs break-any"><?= $p['actual_digest'] ? e(substr((string)$p['actual_digest'], 0, 24)) . '…' : '—' ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (canPublish()): ?>
<?php /* ---------------------------------------------------------------------
   Staff instrumentation.

   The format register, the audit history and the run timings are how the
   library steers preservation work: which formats to migrate first, when the
   last sweep ran, how long it took. They are not something a reader of the
   collection needs, and no public repository exposes them. What a reader does
   need -- that the files are checked, and the result of the last check -- is
   in the summary above and stays public.
   --------------------------------------------------------------------- */ ?>
<section class="section-alt section-sm">
  <div class="container">
    <div class="grid-2" style="align-items:start">

      <div class="card">
        <h2 class="panel-title mb-2">Format register</h2>
        <p class="small muted mb-4">
          What is in storage, and how likely each format is to still open in twenty years.
          Risk shows which formats would need migrating first; the system flags them but does not convert them.
        </p>
        <?php if (!$formats): ?>
        <div class="empty"><i data-ico="archive" class="ico-xl"></i><h4>No files yet</h4>
          <p class="mt-2">The register fills as work is deposited.</p></div>
        <?php else: ?>
        <div class="table-wrap">
          <table class="tbl">
            <caption class="sr-only">Stored formats, counts and preservation risk</caption>
            <thead><tr><th scope="col">Media type</th><th scope="col">PRONOM</th><th scope="col">Files</th><th scope="col">Size</th><th scope="col">Risk</th></tr></thead>
            <tbody>
            <?php foreach ($formats as $f): [$level, $note] = formatRisk((string)$f['mime_type']); ?>
            <tr>
              <td class="mono small break-any"><?= e($f['mime_type']) ?></td>
              <td class="mono xs subtle"><?= e(premisFormatRegistry((string)$f['mime_type'])) ?></td>
              <td><?= number_format((int)$f['n']) ?></td>
              <td class="small subtle"><?= humanBytes((int)$f['bytes']) ?></td>
              <td>
                <span class="badge <?= $level === 'low' ? 'badge-ok' : ($level === 'medium' ? 'badge-warn' : 'badge-danger') ?>">
                  <?= e(ucfirst($level)) ?>
                </span>
                <span class="xs subtle" style="display:block;margin-top:4px"><?= e($note) ?></span>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <div class="card">
        <h2 class="panel-title mb-2">Audit history</h2>
        <p class="small muted mb-4">Each run recomputes every digest and writes a PREMIS fixity-check event per object.</p>
        <?php if (!$audits): ?>
        <div class="empty">
          <i data-ico="shieldcheck" class="ico-xl"></i>
          <h4>No audit has been run yet</h4>
          <p class="mt-2" style="max-width:40ch;margin-inline:auto">
            Digests are recorded at ingest, so the baseline exists. An audit is how you confirm nothing has changed since.
          </p>
        </div>
        <?php else: ?>
        <div class="table-wrap">
          <table class="tbl">
            <caption class="sr-only">Previous fixity audits</caption>
            <thead><tr><th scope="col">Run</th><th scope="col">By</th><th scope="col">Checked</th><th scope="col">Passed</th><th scope="col">Failed</th><th scope="col">Took</th></tr></thead>
            <tbody>
            <?php foreach ($audits as $a): ?>
            <tr>
              <td class="small"><?= humanDate($a['run_at'], 'j M Y, g:i a') ?></td>
              <td class="small subtle"><?= e($a['operator'] ?: 'Scheduled check') ?></td>
              <td><?= number_format((int)$a['objects_checked']) ?></td>
              <td><span class="badge badge-ok"><?= number_format((int)$a['passed']) ?></span></td>
              <td><?= (int)$a['failed'] > 0
                    ? '<span class="badge badge-danger">' . number_format((int)$a['failed']) . '</span>'
                    : '<span class="subtle">0</span>' ?></td>
              <td class="small subtle"><?= number_format((int)$a['duration_ms']) ?> ms</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

        <table class="meta-table mt-6">
          <tr><th scope="row">Digest algorithm</th><td class="mono"><?= DIGEST_LABEL ?></td></tr>
          <tr><th scope="row">PREMIS version</th><td>3.0</td></tr>
          <tr><th scope="row">METS version</th><td>1.12</td></tr>
          <tr><th scope="row">Packages built</th><td><?= number_format((int)$stats['packages']) ?></td></tr>
          <tr><th scope="row">Oldest object</th><td><?= $oldest ? humanDate($oldest) : '—' ?></td></tr>
        </table>
      </div>

    </div>
  </div>
</section>
<?php endif; ?>

<section class="section-sm">
  <div class="container">
    <div class="section-head">
      <h2 class="section-title">What happens to a file after you upload it</h2>
      <p class="section-lede">Six steps, all automatic, all recorded as PREMIS events you can inspect on any record.</p>
    </div>
    <ol class="steps">
      <?php
      $steps = [
        ['upload',      'It is accepted',        'The file is checked for type and size, then written to managed storage under a name the repository controls.'],
        ['fingerprint', 'It is fingerprinted',   DIGEST_LABEL . ' is computed over the bytes and stored alongside the object. This is the baseline every later check compares against.'],
        ['file',        'Its format is identified', 'The media type is recorded and matched to a PRONOM identifier, so the format register knows what it is holding.'],
        ['database',    'It is described',       'Your form entries become the fifteen Dublin Core elements, and the rights statement becomes a PREMIS rights entry.'],
        ['layers',      'It is packaged',        'A METS document is generated that binds the description, the preservation metadata, the file inventory and the chapter structure together.'],
        ['refresh',     'It is re-checked',      'On every audit the digest is recomputed. A mismatch is surfaced immediately rather than discovered years later.'],
      ];
      foreach ($steps as $i => [$ico, $title, $body]): ?>
      <li class="step">
        <span class="feature-ico"><i data-ico="<?= $ico ?>"></i></span>
        <div>
          <h3 class="list-title"><?= e($title) ?></h3>
          <p class="small muted mt-2" style="max-width:60ch"><?= e($body) ?></p>
        </div>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

</main>

<?php include ROOT_PATH . '/templates/layout/footer.php'; ?>
