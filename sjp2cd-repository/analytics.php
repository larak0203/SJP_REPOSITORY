<?php
/**
 * Analytics and reports.
 *
 * The paper's Output stage promises "download statistics, submission activity
 * data, and a usage dashboard for administrators", and the comparison matrix
 * claims a built-in analytics dashboard. This is that page.
 *
 * Every figure is read from the live database. Nothing is estimated, projected
 * or filled in — where there is no data the page says so rather than drawing an
 * empty axis.
 */
require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/includes/metadata.php';
requireRole('admin');

/* ---------------------------------------------------------------- totals --- */
$totals = $pdo->query(
    "SELECT
       (SELECT COUNT(*) FROM records)                                   records,
       (SELECT COUNT(*) FROM records WHERE status='published')           published,
       (SELECT COUNT(*) FROM records WHERE status='archived')            archived,
       (SELECT COUNT(*) FROM records
         WHERE status IN ('submitted','under_review','approved'))        pending,
       (SELECT COALESCE(SUM(downloads),0) FROM records)                  downloads,
       (SELECT COALESCE(SUM(views),0) FROM records)                      views,
       (SELECT COUNT(*) FROM users WHERE is_active=1)                    people,
       (SELECT COUNT(DISTINCT submitted_by) FROM records)                depositors,
       (SELECT COALESCE(SUM(size_bytes),0) FROM record_files)            bytes,
       (SELECT COUNT(*) FROM premis_events)                              events"
)->fetch();

/* Downloads are logged as PREMIS dissemination events, so activity over time is
   read from the preservation record rather than from a counter that only knows
   its current value. */
$monthly = $pdo->query(
    "SELECT DATE_FORMAT(event_datetime, '%Y-%m') ym, COUNT(*) n
     FROM premis_events
     WHERE event_type = 'dissemination'
       AND event_datetime >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
     GROUP BY ym ORDER BY ym"
)->fetchAll();
$monthlyDownloads = array_column($monthly, 'n', 'ym');

$submissions = $pdo->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') ym, COUNT(*) n
     FROM records
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
     GROUP BY ym ORDER BY ym"
)->fetchAll();
$monthlySubs = array_column($submissions, 'n', 'ym');

/* A continuous twelve-month axis, so a quiet month reads as a gap rather than
   being silently skipped. */
$months = [];
for ($i = 11; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("-{$i} months"));
    $months[$key] = [
        'label'     => date('M', strtotime($key . '-01')),
        'downloads' => (int)($monthlyDownloads[$key] ?? 0),
        'deposits'  => (int)($monthlySubs[$key] ?? 0),
    ];
}
$peakDownloads = max(1, max(array_column($months, 'downloads')));
$peakDeposits  = max(1, max(array_column($months, 'deposits')));

/* ------------------------------------------------------------ breakdowns --- */
$byDept = $pdo->query(
    "SELECT d.code, d.name,
            COUNT(r.id) n,
            COALESCE(SUM(r.downloads),0) downloads
     FROM departments d
     LEFT JOIN records r ON r.department_id = d.id AND r.status IN ('published','archived')
     GROUP BY d.id ORDER BY n DESC, d.code"
)->fetchAll();
$deptMax = max(1, (int)($byDept[0]['n'] ?? 1));

$byType = $pdo->query(
    "SELECT dc_type, COUNT(*) n, COALESCE(SUM(downloads),0) downloads
     FROM records WHERE status IN ('published','archived')
     GROUP BY dc_type ORDER BY n DESC"
)->fetchAll();

$topDownloaded = $pdo->query(
    "SELECT r.id, r.dc_title, r.dc_identifier, r.downloads, r.views, d.code
     FROM records r LEFT JOIN departments d ON d.id = r.department_id
     WHERE r.status IN ('published','archived') AND r.downloads > 0
     ORDER BY r.downloads DESC LIMIT 8"
)->fetchAll();

$topDepositors = $pdo->query(
    "SELECT u.name, u.role, COUNT(r.id) n,
            SUM(r.status IN ('published','archived')) published
     FROM users u JOIN records r ON r.submitted_by = u.id
     GROUP BY u.id ORDER BY n DESC LIMIT 6"
)->fetchAll();

$eventMix = $pdo->query(
    "SELECT event_type, COUNT(*) n FROM premis_events GROUP BY event_type ORDER BY n DESC"
)->fetchAll();
$eventTotal = max(1, array_sum(array_column($eventMix, 'n')));

/* Turnaround: how long a deposit waits between submission and publication. */
$turnaround = $pdo->query(
    "SELECT ROUND(AVG(DATEDIFF(published_at, submitted_at)), 1) avg_days,
            MIN(DATEDIFF(published_at, submitted_at)) fastest,
            MAX(DATEDIFF(published_at, submitted_at)) slowest,
            COUNT(*) n
     FROM records
     WHERE published_at IS NOT NULL AND submitted_at IS NOT NULL
       AND published_at >= submitted_at"
)->fetch();

$lastAudit = $pdo->query(
    "SELECT run_at, objects_checked, passed, failed FROM fixity_audits ORDER BY run_at DESC LIMIT 1"
)->fetch();

$pageLabel  = 'Analytics';
$pageSub    = 'Every figure on this page is counted from the live database.';
$topActions = '<a class="btn btn-outline" href="' . url('preservation.php') . '">'
            . '<i data-ico="shieldcheck" class="ico-sm"></i> Preservation report</a>';
include ROOT_PATH . '/templates/layout/app_header.php';
?>

<section class="stat-row mb-6">
  <article data-tilt class="stat stat-blue">
    <span class="stat-ico"><i data-ico="filestack"></i></span>
    <span class="stat-value"><?= number_format((int)$totals['records']) ?></span>
    <span class="stat-label">Records held</span>
    <span class="stat-foot"><?= number_format((int)$totals['published']) ?> live · <?= number_format((int)$totals['archived']) ?> archived</span>
  </article>
  <article data-tilt class="stat stat-mint">
    <span class="stat-ico"><i data-ico="download"></i></span>
    <span class="stat-value"><?= number_format((int)$totals['downloads']) ?></span>
    <span class="stat-label">Full-text downloads</span>
    <span class="stat-foot"><?= number_format((int)$totals['views']) ?> record views</span>
  </article>
  <article data-tilt class="stat stat-gold">
    <span class="stat-ico"><i data-ico="users"></i></span>
    <span class="stat-value"><?= number_format((int)$totals['depositors']) ?></span>
    <span class="stat-label">People who have deposited</span>
    <span class="stat-foot">of <?= number_format((int)$totals['people']) ?> active accounts</span>
  </article>
  <article data-tilt class="stat stat-lilac">
    <span class="stat-ico"><i data-ico="clock"></i></span>
    <span class="stat-value"><?= $turnaround['n'] ? (float)$turnaround['avg_days'] : '—' ?></span>
    <span class="stat-label">Average days to publish</span>
    <span class="stat-foot"><?= $turnaround['n']
        ? 'Fastest ' . (int)$turnaround['fastest'] . ', slowest ' . (int)$turnaround['slowest']
        : 'No published deposits yet' ?></span>
  </article>
</section>

<div class="workspace">
  <div>
    <!-- Activity over twelve months -->
    <section class="card mb-6">
      <div class="panel-head">
        <div>
          <h2 class="panel-title">Activity over the last twelve months</h2>
          <p class="small subtle mt-2">
            Deposits are counted when created; downloads are counted from PREMIS
            dissemination events, so the figure survives even if a counter is reset.
          </p>
        </div>
        <div class="legend">
          <span class="legend-item"><span class="sw" style="background:var(--chart-1)"></span> Deposits</span>
          <span class="legend-item"><span class="sw" style="background:var(--chart-2)"></span> Downloads</span>
        </div>
      </div>

      <?php if (array_sum(array_column($months, 'deposits')) + array_sum(array_column($months, 'downloads')) === 0): ?>
      <div class="empty">
        <i data-ico="chart" class="ico-xl"></i>
        <h4>No activity in the last twelve months</h4>
        <p class="mt-2">Deposits and downloads will appear here as they happen.</p>
      </div>
      <?php else: ?>
      <div class="bars" role="img" aria-label="<?php
        $desc = [];
        foreach ($months as $m) $desc[] = $m['label'] . ': ' . $m['deposits'] . ' deposits, ' . $m['downloads'] . ' downloads';
        echo e(implode('. ', $desc)); ?>">
        <?php foreach ($months as $m): ?>
        <div class="bar-col">
          <span class="num"><?= $m['deposits'] ?: '' ?></span>
          <span class="bar-pair">
            <span class="bar" style="height:<?= (int)round($m['deposits'] / $peakDeposits * 120) ?>px"></span>
            <span class="bar bar-alt" style="height:<?= (int)round($m['downloads'] / $peakDownloads * 120) ?>px"></span>
          </span>
          <span class="bar-lab"><?= e($m['label']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <!-- By programme -->
    <section class="card mb-6">
      <div class="panel-head">
        <div>
          <h2 class="panel-title">Holdings by programme</h2>
          <p class="small subtle mt-2">Published and archived records, with the downloads each programme has attracted.</p>
        </div>
      </div>
      <?php if (!array_filter(array_column($byDept, 'n'))): ?>
      <div class="empty"><i data-ico="cap" class="ico-xl"></i>
        <h4>Nothing published yet</h4><p class="mt-2">Programme totals appear once records are published.</p></div>
      <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <caption class="sr-only">Records and downloads by programme</caption>
          <thead><tr><th scope="col">Programme</th><th scope="col">Records</th><th scope="col">Share</th><th scope="col">Downloads</th></tr></thead>
          <tbody>
          <?php foreach ($byDept as $d): ?>
          <tr>
            <td><span class="strong"><?= e($d['code']) ?></span> <span class="small subtle"><?= e($d['name']) ?></span></td>
            <td><?= (int)$d['n'] ?></td>
            <td style="min-width:150px">
              <div class="hbar-row">
                <span class="hbar-track"><span class="hbar-fill" style="width:<?= (int)round((int)$d['n'] / $deptMax * 100) ?>%"></span></span>
              </div>
            </td>
            <td><?= number_format((int)$d['downloads']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>

    <!-- Most downloaded -->
    <section class="card">
      <div class="panel-head">
        <div>
          <h2 class="panel-title">Most downloaded</h2>
          <p class="small subtle mt-2">What the collection is actually being used for.</p>
        </div>
        <a class="btn btn-ghost btn-sm" href="<?= url('browse.php?sort=downloads') ?>">See all <i data-ico="chevright" class="ico-sm"></i></a>
      </div>
      <?php if (!$topDownloaded): ?>
      <div class="empty"><i data-ico="download" class="ico-xl"></i>
        <h4>Nothing has been downloaded yet</h4>
        <p class="mt-2">This fills in as readers retrieve full texts.</p></div>
      <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <caption class="sr-only">The most downloaded records</caption>
          <thead><tr><th scope="col">Record</th><th scope="col">Programme</th><th scope="col">Views</th><th scope="col">Downloads</th></tr></thead>
          <tbody>
          <?php foreach ($topDownloaded as $r): ?>
          <tr>
            <td>
              <a href="<?= url('record.php?id=' . (int)$r['id']) ?>" class="strong"><?= e(mb_strimwidth((string)$r['dc_title'], 0, 58, '…')) ?></a>
              <span class="mono xs subtle" style="display:block"><?= e($r['dc_identifier'] ?: '') ?></span>
            </td>
            <td class="small"><?= e($r['code'] ?: '—') ?></td>
            <td class="small subtle"><?= number_format((int)$r['views']) ?></td>
            <td class="strong"><?= number_format((int)$r['downloads']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>
  </div>

  <aside class="rail">
    <section class="card">
      <h2 class="panel-title mb-4">By kind of work</h2>
      <?php if (!$byType): ?>
      <p class="small subtle">Nothing published yet.</p>
      <?php else: foreach ($byType as $t): ?>
      <div class="hbar-row mb-3">
        <span class="grow" style="min-width:0">
          <span class="list-title truncate"><?= e($t['dc_type']) ?></span>
          <span class="hbar-track"><span class="hbar-fill"
            style="width:<?= (int)round((int)$t['n'] / max(1, (int)$byType[0]['n']) * 100) ?>%"></span></span>
        </span>
        <span class="small strong"><?= (int)$t['n'] ?></span>
      </div>
      <?php endforeach; endif; ?>
    </section>

    <section class="card">
      <h2 class="panel-title mb-4">Who is depositing</h2>
      <?php if (!$topDepositors): ?>
      <p class="small subtle">No deposits yet.</p>
      <?php else: foreach ($topDepositors as $d): ?>
      <div class="list-item">
        <span class="avatar avatar-sm <?= $d['role'] === 'admin' ? 'avatar-gold' : ($d['role'] === 'faculty' ? 'avatar-violet' : 'avatar-mint') ?>" aria-hidden="true">
          <?= e(mb_strtoupper(mb_substr((string)$d['name'], 0, 2))) ?>
        </span>
        <span class="grow" style="min-width:0">
          <span class="list-title truncate"><?= e($d['name']) ?></span>
          <span class="list-desc"><?= (int)$d['n'] ?> deposit<?= (int)$d['n'] === 1 ? '' : 's' ?>, <?= (int)$d['published'] ?> published</span>
        </span>
      </div>
      <?php endforeach; endif; ?>
    </section>

    <section class="card">
      <h2 class="panel-title mb-4">Preservation activity</h2>
      <table class="meta-table">
        <?php foreach ($eventMix as $e): ?>
        <tr>
          <th scope="row" class="mono xs"><?= e($e['event_type']) ?></th>
          <td><?= number_format((int)$e['n']) ?>
            <span class="xs subtle">(<?= (int)round((int)$e['n'] / $eventTotal * 100) ?>%)</span></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <hr class="divider">
      <table class="meta-table">
        <tr><th scope="row">Stored</th><td><?= humanBytes((int)$totals['bytes']) ?></td></tr>
        <tr><th scope="row">Digest</th><td><?= DIGEST_LABEL ?></td></tr>
        <tr><th scope="row">Last audit</th><td><?= $lastAudit
            ? timeAgo($lastAudit['run_at']) . ' — ' . (int)$lastAudit['passed'] . '/' . (int)$lastAudit['objects_checked'] . ' passed'
            : 'Never run' ?></td></tr>
      </table>
      <a class="btn btn-outline btn-sm btn-block mt-4" href="<?= url('preservation.php') ?>">
        <i data-ico="shieldcheck" class="ico-sm"></i> Run an integrity audit
      </a>
    </section>
  </aside>
</div>

<?php include ROOT_PATH . '/templates/layout/app_footer.php'; ?>
