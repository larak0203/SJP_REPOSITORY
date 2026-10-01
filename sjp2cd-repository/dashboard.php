<?php
require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/includes/metadata.php';
requireLogin();

$uid  = currentUserId();
$role = currentRole();

/* The five-year archive rule from the Scope and Limitation. Throttled to once a
   day and idempotent, so opening the dashboard cannot cost anything twice. */
$sweptNow = canPublish() ? runArchiveSweep($pdo, $uid) : 0;
if ($sweptNow) {
    flash("info", $sweptNow . " record" . ($sweptNow === 1 ? " was" : "s were")
        . " moved to the archive automatically, having passed " . ARCHIVE_AFTER_YEARS . " years.");
}

/* ---- figures each role actually needs ---- */
$mine = $pdo->prepare(
    "SELECT status, COUNT(*) n FROM records WHERE submitted_by = ? GROUP BY status"
);
$mine->execute([$uid]);
$myStatus = array_column($mine->fetchAll(), 'n', 'status');
$myTotal  = array_sum($myStatus);

$reach = $pdo->prepare("SELECT COALESCE(SUM(views),0) v, COALESCE(SUM(downloads),0) d FROM records WHERE submitted_by = ?");
$reach->execute([$uid]);
$myReach = $reach->fetch();

$pending = 0;
if (canReview()) {
    if (isAdmin()) {
        $pending = (int)$pdo->query("SELECT COUNT(*) FROM records WHERE status IN ('submitted','under_review','approved')")->fetchColumn();
    } else {
        $s = $pdo->prepare("SELECT COUNT(*) FROM records WHERE adviser_id = ? AND status IN ('submitted','under_review')");
        $s->execute([$uid]);
        $pending = (int)$s->fetchColumn();
    }
}

$repo = $pdo->query(
    "SELECT
       (SELECT COUNT(*) FROM records WHERE status='published')                       published,
       (SELECT COUNT(*) FROM records)                                                 total,
       (SELECT COALESCE(SUM(downloads),0) FROM records)                               downloads,
       (SELECT COUNT(*) FROM record_files)                                            files,
       (SELECT COALESCE(SUM(size_bytes),0) FROM record_files)                         bytes,
       (SELECT COUNT(*) FROM premis_events)                                           events,
       (SELECT COUNT(*) FROM mets_packages)                                           packages,
       (SELECT COUNT(*) FROM users WHERE is_active=1)                                 people"
)->fetch();

/* work that needs this person next */
if (canReview() && !isAdmin()) {
    $queue = $pdo->prepare(
        "SELECT r.*, u.name AS author FROM records r JOIN users u ON u.id = r.submitted_by
         WHERE r.adviser_id = ? AND r.status IN ('submitted','under_review')
         ORDER BY r.submitted_at ASC LIMIT 5"
    );
    $queue->execute([$uid]);
} elseif (isAdmin()) {
    $queue = $pdo->query(
        "SELECT r.*, u.name AS author FROM records r JOIN users u ON u.id = r.submitted_by
         WHERE r.status IN ('approved','submitted','under_review')
         ORDER BY FIELD(r.status,'approved','under_review','submitted'), r.submitted_at ASC LIMIT 5"
    );
} else {
    $queue = $pdo->prepare(
        "SELECT r.*, u.name AS author FROM records r JOIN users u ON u.id = r.submitted_by
         WHERE r.submitted_by = ? AND r.status IN ('revision','rejected','draft')
         ORDER BY r.updated_at DESC LIMIT 5"
    );
    $queue->execute([$uid]);
}
$queueRows = $queue->fetchAll();

$recent = $pdo->prepare(
    "SELECT r.*, d.name AS department_name FROM records r
     LEFT JOIN departments d ON d.id = r.department_id
     WHERE r.submitted_by = ? ORDER BY r.updated_at DESC LIMIT 4"
);
$recent->execute([$uid]);
$recentRows = $recent->fetchAll();

$feed = $pdo->prepare(
    "SELECT a.*, u.name FROM activity_log a LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.created_at DESC LIMIT 7"
);
$feed->execute();
$feedRows = $feed->fetchAll();

$byType = $pdo->query(
    "SELECT dc_type, COUNT(*) n FROM records WHERE status='published' GROUP BY dc_type ORDER BY n DESC"
)->fetchAll();
$typeMax = max(1, (int)($byType[0]['n'] ?? 1));

$notes = $pdo->prepare(
    "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5"
);
$notes->execute([$uid]);
$noteRows = $notes->fetchAll();

/* The four figures named in the paper's Figure 3. Library staff see the whole
   repository; everyone else sees their own contribution, so the dashboard never
   reports numbers a person has no business seeing. */
if (isAdmin()) {
    $cards = $pdo->query(
        "SELECT COUNT(*) total,
                SUM(status IN ('published','archived')) approved,
                SUM(status IN ('submitted','under_review','approved')) pending,
                COALESCE(SUM(downloads),0) downloads
         FROM records"
    )->fetch();
    $cardScope = 'across the repository';
} elseif (canReview()) {
    $c = $pdo->prepare(
        "SELECT COUNT(*) total,
                SUM(status IN ('published','archived')) approved,
                SUM(status IN ('submitted','under_review')) pending,
                COALESCE(SUM(downloads),0) downloads
         FROM records WHERE submitted_by = :me1 OR adviser_id = :me2"
    );
    $c->execute([':me1' => $uid, ':me2' => $uid]);
    $cards = $c->fetch();
    $cardScope = 'your deposits and those you advise';
} else {
    $c = $pdo->prepare(
        "SELECT COUNT(*) total,
                SUM(status IN ('published','archived')) approved,
                SUM(status IN ('submitted','under_review','approved')) pending,
                COALESCE(SUM(downloads),0) downloads
         FROM records WHERE submitted_by = ?"
    );
    $c->execute([$uid]);
    $cards = $c->fetch();
    $cardScope = 'your deposits';
}

$me = currentUser($pdo);
$firstName = strtok((string)$me['name'], ' ');
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$pageLabel = 'Dashboard';
$pageSub   = $greeting . ', ' . e($firstName) . '.';
$topActions = canDeposit()
    ? '<a class="btn btn-accent" href="' . url('submit.php') . '"><i data-ico="upload" class="ico-sm"></i> Deposit work</a>'
    : '<a class="btn btn-accent" href="' . url('browse.php') . '"><i data-ico="search" class="ico-sm"></i> Browse the collection</a>';
include ROOT_PATH . '/templates/layout/app_header.php';
?>

<section class="stat-row mb-6">
  <article data-tilt class="stat stat-blue">
    <span class="stat-ico"><i data-ico="filestack"></i></span>
    <span class="stat-value"><?= number_format((int)$cards['total']) ?></span>
    <span class="stat-label">Total documents</span>
    <span class="stat-foot"><?= e($cardScope) ?></span>
  </article>

  <article data-tilt class="stat stat-mint">
    <span class="stat-ico"><i data-ico="checkcircle"></i></span>
    <span class="stat-value"><?= number_format((int)$cards['approved']) ?></span>
    <span class="stat-label">Approved</span>
    <span class="stat-foot">Published in the catalogue</span>
  </article>

  <article data-tilt class="stat stat-gold">
    <span class="stat-ico"><i data-ico="clock"></i></span>
    <span class="stat-value"><?= number_format((int)$cards['pending']) ?></span>
    <span class="stat-label">Pending review</span>
    <span class="stat-foot"><?= (int)$cards['pending'] ? 'Awaiting a decision' : 'Nothing waiting' ?></span>
  </article>

  <article data-tilt class="stat stat-lilac">
    <span class="stat-ico"><i data-ico="download"></i></span>
    <span class="stat-value"><?= number_format((int)$cards['downloads']) ?></span>
    <span class="stat-label">Total downloads</span>
    <span class="stat-foot">Full-text retrievals</span>
  </article>
</section>

<!-- Quick Actions, as described in Figure 3 of the paper. Each button is the
     entry point to a task the reader performs often, so the common journeys
     cost one click from the dashboard rather than a hunt through the sidebar. -->
<section class="card quick mb-8">
  <h2 class="panel-title mb-4"><i data-ico="sparkle" class="ico-sm"></i> Quick actions</h2>
  <div class="quick-row">
    <?php if (canDeposit()): ?>
    <a class="btn btn-primary" href="<?= url('submit.php') ?>">
      <i data-ico="upload" class="ico-sm"></i> Submit new research
    </a>
    <?php endif; ?>
    <a class="btn btn-outline" href="<?= url('browse.php') ?>">
      <i data-ico="search" class="ico-sm"></i> Browse repository
    </a>
    <a class="btn btn-outline" href="<?= url('my-work.php') ?>">
      <i data-ico="filestack" class="ico-sm"></i> My submissions
    </a>
    <?php if (canReview()): ?>
    <a class="btn btn-outline" href="<?= url('review.php') ?>">
      <i data-ico="checkcircle" class="ico-sm"></i> Review pending<?= $pending ? ' (' . (int)$pending . ')' : '' ?>
    </a>
    <?php else: ?>
    <a class="btn btn-outline" href="<?= url('preservation.php') ?>">
      <i data-ico="shieldcheck" class="ico-sm"></i> Preservation report
    </a>
    <?php endif; ?>
  </div>
</section>

<div class="workspace">
  <div>
    <section class="card mb-6">
      <div class="panel-head">
        <div>
          <h2 class="panel-title"><?= isAdmin() ? 'Ready for the library' : (canReview() ? 'Submissions to review' : 'Needs your attention') ?></h2>
          <p class="small subtle mt-2">
            <?= isAdmin()
                ? 'Approved deposits become public the moment you publish them.'
                : (canReview()
                    ? 'Students you advise are waiting on a decision.'
                    : 'Deposits that came back to you, and drafts you never finished.') ?>
          </p>
        </div>
        <?php if ($queueRows): ?>
        <a class="btn btn-ghost btn-sm" href="<?= url(canReview() ? 'review.php' : 'my-work.php') ?>">See all <i data-ico="chevright" class="ico-sm"></i></a>
        <?php endif; ?>
      </div>

      <?php if (!$queueRows): ?>
      <div class="empty">
        <i data-ico="checkcircle" class="ico-xl"></i>
        <h4><?= canReview() ? 'Nothing is waiting' : 'You are all caught up' ?></h4>
        <p class="mt-2"><?= canReview()
            ? 'New submissions land here as soon as they are sent for review.'
            : (canDeposit()
                ? 'Start a deposit whenever your paper is ready.'
                : 'Anything addressed to you will appear here.') ?></p>
        <?php if (!canReview() && canDeposit()): ?>
        <a class="btn btn-outline mt-6" href="<?= url('submit.php') ?>">Deposit work</a>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <?php foreach ($queueRows as $r): ?>
      <a class="list-item" href="<?= url((canReview() ? 'review.php?id=' : 'record.php?id=') . (int)$r['id']) ?>">
        <span class="file-ico"><i data-ico="file"></i></span>
        <span class="grow" style="min-width:0">
          <span class="list-title truncate"><?= e($r['dc_title']) ?></span>
          <span class="list-desc truncate"><?= e($r['author']) ?> · <?= e($r['dc_type']) ?></span>
        </span>
        <?= statusBadge((string)$r['status']) ?>
        <span class="list-time"><?= timeAgo($r['submitted_at'] ?: $r['updated_at']) ?></span>
      </a>
      <?php endforeach; ?>
      <?php endif; ?>
    </section>

    <section class="card mb-6">
      <div class="panel-head">
        <div>
          <h2 class="panel-title">Your recent deposits</h2>
          <p class="small subtle mt-2">Every record carries Dublin Core, PREMIS and METS from the moment it is deposited.</p>
        </div>
        <a class="btn btn-ghost btn-sm" href="<?= url('my-work.php') ?>">My deposits <i data-ico="chevright" class="ico-sm"></i></a>
      </div>

      <?php if (!$recentRows): ?>
      <div class="empty">
        <i data-ico="upload" class="ico-xl"></i>
        <h4>No deposits yet</h4>
        <p class="mt-2" style="max-width:46ch;margin-inline:auto"><?= canDeposit()
          ? 'Depositing takes one form. The system mints the identifier, checksums the file and builds the preservation metadata for you.'
          : 'Deposits are made by faculty and the library. Ask the adviser who supervised your work to deposit it, naming you as its author.' ?></p>
        <?php if (canDeposit()): ?>
        <a class="btn btn-accent mt-6" href="<?= url('submit.php') ?>"><i data-ico="upload" class="ico-sm"></i> Deposit your first paper</a>
        <?php else: ?>
        <a class="btn btn-outline mt-6" href="<?= url('about.php') ?>">How deposits work</a>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div class="table-wrap">
        <table class="tbl">
          <caption class="sr-only">Your most recent deposits</caption>
          <thead><tr><th scope="col">Title</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Metadata</th><th scope="col">Updated</th></tr></thead>
          <tbody>
          <?php foreach ($recentRows as $r): $c = metadataCompleteness($r); ?>
          <tr>
            <td><a href="<?= url('record.php?id=' . (int)$r['id']) ?>" class="strong"><?= e(mb_strimwidth((string)$r['dc_title'], 0, 70, '…')) ?></a></td>
            <td class="small"><?= e($r['dc_type']) ?></td>
            <td><?= statusBadge((string)$r['status']) ?></td>
            <td style="min-width:130px">
              <div class="hbar-row">
                <span class="hbar-track"><span class="hbar-fill" style="width:<?= (int)$c['percent'] ?>%"></span></span>
                <span class="xs subtle"><?= (int)$c['percent'] ?>%</span>
              </div>
            </td>
            <td class="small subtle"><?= timeAgo($r['updated_at']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>

    <?php if ($byType): ?>
    <section class="card">
      <div class="panel-head">
        <div>
          <h2 class="panel-title">What the collection holds</h2>
          <p class="small subtle mt-2">Published records by kind of work.</p>
        </div>
      </div>
      <div class="bars" role="img" aria-label="Published records by type: <?php
        echo e(implode(', ', array_map(fn($t) => $t['dc_type'] . ' ' . $t['n'], $byType))); ?>">
        <?php foreach ($byType as $t): ?>
        <div class="bar-col">
          <span class="num"><?= (int)$t['n'] ?></span>
          <span class="bar" style="height:<?= max(8, (int)round((int)$t['n'] / $typeMax * 130)) ?>px"></span>
          <span class="bar-lab"><?= e($t['dc_type']) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>

  <aside class="rail">
    <section class="card">
      <div class="panel-head"><h2 class="panel-title">Notifications</h2>
        <a class="btn btn-ghost btn-sm" href="<?= url('notifications.php') ?>">All</a></div>
      <?php if (!$noteRows): ?>
      <p class="small subtle">Nothing yet. You will hear from the system when a deposit moves.</p>
      <?php else: foreach ($noteRows as $n): ?>
      <a class="list-item" href="<?= url($n['record_id'] ? 'record.php?id=' . (int)$n['record_id'] : 'notifications.php') ?>">
        <span class="dot <?= $n['is_read'] ? '' : 'is-active' ?>" aria-hidden="true"></span>
        <span class="grow" style="min-width:0">
          <span class="list-title truncate"><?= e($n['title']) ?></span>
          <span class="list-time"><?= timeAgo($n['created_at']) ?></span>
        </span>
      </a>
      <?php endforeach; endif; ?>
    </section>

    <section class="card">
      <h2 class="panel-title mb-4">Repository at a glance</h2>
      <table class="meta-table">
        <tr><th scope="row">Records</th><td><?= number_format((int)$repo['total']) ?></td></tr>
        <tr><th scope="row">Files preserved</th><td><?= number_format((int)$repo['files']) ?></td></tr>
        <tr><th scope="row">Storage</th><td><?= humanBytes((int)$repo['bytes']) ?></td></tr>
        <tr><th scope="row">Checksums</th><td><?= DIGEST_LABEL ?></td></tr>
        <tr><th scope="row">Members</th><td><?= number_format((int)$repo['people']) ?></td></tr>
      </table>
      <a class="btn btn-outline btn-sm btn-block mt-4" href="<?= url('preservation.php') ?>">
        <i data-ico="shieldcheck" class="ico-sm"></i> Preservation report
      </a>
    </section>

    <?php if ($feedRows): ?>
    <section class="card">
      <h2 class="panel-title mb-4">Latest activity</h2>
      <ol class="timeline">
        <?php foreach ($feedRows as $f): ?>
        <li class="tl-item">
          <span class="tl-dot" aria-hidden="true"></span>
          <p class="small"><span class="strong"><?= e($f['name'] ?? 'System') ?></span> <?= e($f['action']) ?></p>
          <p class="xs subtle"><?= timeAgo($f['created_at']) ?></p>
        </li>
        <?php endforeach; ?>
      </ol>
    </section>
    <?php endif; ?>
  </aside>
</div>

<?php include ROOT_PATH . '/templates/layout/app_footer.php'; ?>
