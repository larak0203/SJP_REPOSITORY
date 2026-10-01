<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$uid = currentUserId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = (string)($_POST['action'] ?? '');

    if ($act === 'read_all') {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0")->execute([$uid]);
        flash('ok', 'All caught up.');
    } elseif ($act === 'read') {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")
            ->execute([(int)($_POST['id'] ?? 0), $uid]);
    } elseif ($act === 'clear_read') {
        $n = $pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1");
        $n->execute([$uid]);
        flash('ok', 'Cleared ' . $n->rowCount() . ' read notification' . ($n->rowCount() === 1 ? '' : 's') . '.');
    }
    redirect('notifications.php' . (($_POST['filter'] ?? '') === 'unread' ? '?show=unread' : ''));
}

$show   = (string)($_GET['show'] ?? '');
$onlyNew = $show === 'unread';

$sql = "SELECT n.*, r.dc_title, r.status AS record_status
        FROM notifications n
        LEFT JOIN records r ON r.id = n.record_id
        WHERE n.user_id = ?" . ($onlyNew ? " AND n.is_read = 0" : "") . "
        ORDER BY n.is_read ASC, n.created_at DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute([$uid]);
$rows = $stmt->fetchAll();

$unread = unreadNotifications($pdo, $uid);
$tc = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$tc->execute([$uid]);
$total = (int)$tc->fetchColumn();

/** Icon and tone for each notification type the system emits. */
function noteStyle(string $type): array {
    return match ($type) {
        'published' => ['checkcircle', 'ok'],
        'approved'  => ['checkcircle', 'ok'],
        'revision'  => ['edit',        'warn'],
        'rejected'  => ['xcircle',     'danger'],
        'archived'  => ['archive',     'warn'],
        'review'    => ['bell',        'brand'],
        'message'   => ['mail',        'brand'],
        'account'   => ['user',        'brand'],
        default     => ['info',        'brand'],
    };
}

$pageLabel = 'Notifications';
$pageSub   = $unread > 0
    ? $unread . ' unread of ' . number_format($total) . '.'
    : 'Nothing unread.';
$topActions = '';
if ($unread > 0) {
    $topActions = '<form method="post" style="display:contents">' . csrfField()
        . '<input type="hidden" name="action" value="read_all">'
        . '<input type="hidden" name="filter" value="' . e($show) . '">'
        . '<button class="btn btn-outline" type="submit"><i data-ico="check" class="ico-sm"></i> Mark all read</button></form>';
}
include ROOT_PATH . '/templates/layout/app_header.php';
?>

<div class="row-between wrap mb-6" style="gap:var(--sp-3)">
  <div class="chips">
    <a class="chip <?= $onlyNew ? '' : 'is-active' ?>" href="<?= url('notifications.php') ?>">
      Everything <span class="facet-count"><?= number_format($total) ?></span>
    </a>
    <a class="chip <?= $onlyNew ? 'is-active' : '' ?>" href="<?= url('notifications.php?show=unread') ?>">
      Unread <span class="facet-count"><?= number_format($unread) ?></span>
    </a>
  </div>

  <?php if ($total > $unread): ?>
  <form method="post" onsubmit="return confirm('Delete every notification you have already read?')">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="clear_read">
    <input type="hidden" name="filter" value="<?= e($show) ?>">
    <button class="btn btn-ghost btn-sm" type="submit"><i data-ico="trash" class="ico-sm"></i> Clear the read ones</button>
  </form>
  <?php endif; ?>
</div>

<?php if (!$rows): ?>
<div class="card">
  <div class="empty">
    <i data-ico="bell" class="ico-xl"></i>
    <h4><?= $onlyNew ? 'Nothing unread' : 'No notifications yet' ?></h4>
    <p class="mt-2" style="max-width:46ch;margin-inline:auto">
      <?= $onlyNew
          ? 'You have read everything. Older notifications are still here.'
          : 'The system writes here whenever a deposit of yours moves through review, or someone needs you.' ?>
    </p>
    <?php if ($onlyNew): ?>
    <a class="btn btn-outline mt-6" href="<?= url('notifications.php') ?>">Show everything</a>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>

<section class="card" style="padding:0">
<?php foreach ($rows as $n): [$ico, $tone] = noteStyle((string)$n['type']); ?>
  <div class="list-item<?= (int)$n['is_read'] ? '' : ' is-active' ?>" style="align-items:flex-start">
    <span class="file-ico <?= $tone === 'ok' ? 'mint' : ($tone === 'warn' ? 'gold' : ($tone === 'danger' ? 'err' : '')) ?>">
      <i data-ico="<?= $ico ?>"></i>
    </span>

    <span class="grow" style="min-width:0">
      <span class="row wrap" style="gap:8px">
        <span class="list-title"><?= e($n['title']) ?></span>
        <?php if (!(int)$n['is_read']): ?><span class="badge badge-brand">New</span><?php endif; ?>
      </span>
      <?php if ($n['message']): ?>
      <span class="list-desc" style="white-space:normal"><?= e($n['message']) ?></span>
      <?php endif; ?>
      <?php if ($n['dc_title']): ?>
      <a class="small" href="<?= url('record.php?id=' . (int)$n['record_id']) ?>">
        <i data-ico="file" class="ico-sm"></i> <?= e(mb_strimwidth((string)$n['dc_title'], 0, 70, '…')) ?>
      </a>
      <?php endif; ?>
      <span class="list-time" style="display:block;margin-top:4px"><?= timeAgo($n['created_at']) ?></span>
    </span>

    <span class="row" style="gap:8px;align-items:center">
      <?php if ($n['record_id']): ?>
      <a class="btn btn-outline btn-sm" href="<?= url('record.php?id=' . (int)$n['record_id']) ?>">Open</a>
      <?php endif; ?>
      <?php if (!(int)$n['is_read']): ?>
      <form method="post" style="display:contents">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="read">
        <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
        <input type="hidden" name="filter" value="<?= e($show) ?>">
        <button class="btn btn-ghost btn-sm btn-icon" type="submit" aria-label="Mark “<?= e($n['title']) ?>” as read">
          <i data-ico="check" class="ico-sm"></i>
        </button>
      </form>
      <?php endif; ?>
    </span>
  </div>
<?php endforeach; ?>
</section>
<?php endif; ?>

<?php include ROOT_PATH . '/templates/layout/app_footer.php'; ?>
