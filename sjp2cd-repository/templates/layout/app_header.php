<?php
/* Signed-in shell: sidebar + main column.
   Set $pageLabel, optionally $pageSub and $topActions, before including. */
requireLogin();

$me       = currentUser($pdo);
$role     = currentRole();
$here     = basename($_SERVER['PHP_SELF'], '.php');
$nUnread  = unreadNotifications($pdo, currentUserId());
$mUnread  = unreadMessages($pdo, currentUserId());
$pageLabel = $pageLabel ?? 'Dashboard';

$nav = static function (string $page, string $icon, string $label, int $badge = 0) use ($here): void {
    $active = $here === $page ? ' aria-current="page"' : '';
    echo '<a class="nav-item" href="' . url($page . '.php') . '"' . $active . '>'
       . '<i data-ico="' . $icon . '"></i> ' . e($label)
       . ($badge > 0 ? '<span class="badge badge-warn">' . ($badge > 9 ? '9+' : $badge) . '</span>' : '')
       . '</a>';
};

$avatarUrl = null;
if (!empty($me['avatar']) && is_file(AVATAR_PATH . '/' . $me['avatar'])) {
    $avatarUrl = url('uploads/avatars/' . $me['avatar']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<!-- Applied before first paint: the site is dark by default, so a reader who
     has chosen light must not see a dark flash on every navigation. -->
<script>(function(){var d=document.documentElement;d.classList.add('js');
try{var t=localStorage.getItem('sjp2cd-theme');if(t)d.setAttribute('data-theme',t);}catch(e){}})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageLabel) ?> — <?= APP_SHORT ?></title>
<link rel="icon" href="<?= url('assets/img/logo.svg') ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700&family=JetBrains+Mono:wght@400;600&display=swap">
<link rel="stylesheet" href="<?= url('assets/css/design-system.css') ?>?v=<?= @filemtime(ROOT_PATH . '/assets/css/design-system.css') ?: time() ?>">
<?= $extraHead ?? '' ?>
</head>
<body>
<a class="skip-link" href="#main">Skip to main content</a>

<div class="app">
  <nav class="sidebar" aria-label="<?= e(ucfirst($role)) ?> navigation">
    <a class="brand sidebar-brand" href="<?= url('index.php') ?>">
      <span class="brand-logo"><img src="<?= url('assets/img/logo.svg') ?>" alt=""></span>
      <span>
        <span class="brand-name">SJP2CD</span><br>
        <span class="brand-sub"><?= e(ucfirst($role)) ?></span>
      </span>
    </a>

    <div class="card card-tight" style="margin-bottom:var(--sp-4)">
      <div class="row" style="gap:var(--sp-3)">
        <?php if ($avatarUrl): ?>
        <img class="avatar" src="<?= e($avatarUrl) ?>" alt="" style="object-fit:cover">
        <?php else: ?>
        <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string)$me['name'], 0, 2))) ?></span>
        <?php endif; ?>
        <span class="grow" style="min-width:0">
          <span class="list-title truncate" style="display:block"><?= e($me['name']) ?></span>
          <span class="xs subtle truncate" style="display:block"><?= e($me['department_name'] ?? ucfirst($role)) ?></span>
        </span>
      </div>
    </div>

    <div class="nav-group" style="margin-top:0">
      <div class="nav-label">Repository</div>
      <?php
        $nav('dashboard', 'home',      'Dashboard');
        $nav('browse',    'search',    'Browse');
        /* A visitor has nothing to deposit and nothing deposited, so neither
           entry is offered: an empty page reached from the sidebar reads as a
           broken feature rather than a rule. */
        if (canDeposit()) $nav('submit',  'upload',    'Deposit work');
        if (!isGuest())   $nav('my-work', 'filestack', 'My deposits');
      ?>
    </div>

    <?php if (canReview()): ?>
    <div class="nav-group">
      <div class="nav-label"><?= isAdmin() ? 'Library' : 'Supervision' ?></div>
      <?php
        $reviewCount = 0;
        if (isAdmin()) {
            $reviewCount = (int)$pdo->query("SELECT COUNT(*) FROM records WHERE status IN ('approved','submitted','under_review')")->fetchColumn();
        } else {
            $rc = $pdo->prepare("SELECT COUNT(*) FROM records WHERE adviser_id = ? AND status IN ('submitted','under_review')");
            $rc->execute([currentUserId()]);
            $reviewCount = (int)$rc->fetchColumn();
        }
        $nav('review', 'checkcircle', 'Review queue', $reviewCount);
        if (isAdmin()) {
            $nav('manage-records', 'archive', 'All records');
            $nav('analytics',      'chart',   'Analytics');
            $nav('manage-users',   'users',   'People');
        }
      ?>
    </div>
    <?php endif; ?>

    <div class="nav-group">
      <div class="nav-label">Preservation</div>
      <?php
        $nav('preservation', 'shieldcheck', 'Preservation');
        $nav('standards',    'database',    'Metadata guide');
      ?>
    </div>

    <div class="nav-group">
      <div class="nav-label">You</div>
      <?php
        if (!isGuest()) $nav('messages', 'mail', 'Messages', $mUnread);
        $nav('notifications', 'bell',     'Notifications', $nUnread);
        $nav('profile',       'settings', 'Profile');
      ?>
    </div>

    <div class="sidebar-foot">
      <a class="nav-item" href="<?= url('logout.php') ?>"><i data-ico="logout"></i> Sign out</a>
    </div>
  </nav>

  <main class="main" id="main">
    <div class="app-topbar">
      <div class="row" style="gap:var(--sp-3)">
        <button class="btn btn-ghost btn-icon menu-toggle" data-sidebar-toggle type="button"
                aria-expanded="false" aria-label="Open navigation"><i data-ico="menu"></i></button>
        <div>
          <h1 class="app-title"><?= e($pageLabel) ?></h1>
          <?php if (!empty($pageSub)): ?><p class="app-sub"><?= $pageSub ?></p><?php endif; ?>
        </div>
      </div>
      <div class="row" style="gap:var(--sp-2)">
        <button class="btn btn-ghost btn-icon" data-theme-toggle type="button" aria-pressed="false" aria-label="Switch appearance"></button>
        <?= $topActions ?? '' ?>
      </div>
    </div>

    <?php foreach (takeFlashes() as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?> mb-6" role="<?= $f['type'] === 'danger' ? 'alert' : 'status' ?>">
      <i data-ico="<?= $f['type'] === 'ok' ? 'checkcircle' : ($f['type'] === 'danger' ? 'alert' : 'info') ?>" class="ico-sm"></i>
      <span><?= $f['message'] ?></span>
    </div>
    <?php endforeach; ?>
