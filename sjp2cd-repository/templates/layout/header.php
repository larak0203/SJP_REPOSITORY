<?php
/* Public chrome. Set $pageTitle and $navActive before including. */
$navActive = $navActive ?? '';
$cur = static fn(string $k): string => $navActive === $k ? ' aria-current="page"' : '';
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
<title><?= isset($pageTitle) ? e($pageTitle) . ' — ' . APP_SHORT : APP_NAME ?></title>
<meta name="description" content="The institutional repository of St. John Paul II College of Davao. Deposit, describe, preserve and find the College's theses, capstone projects and research.">
<link rel="icon" href="<?= url('assets/img/logo.svg') ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700&family=JetBrains+Mono:wght@400;600&display=swap">
<link rel="stylesheet" href="<?= url('assets/css/design-system.css') ?>?v=<?= @filemtime(ROOT_PATH . '/assets/css/design-system.css') ?: time() ?>">
<?= $extraHead ?? '' ?>
</head>
<body>
<a class="skip-link" href="#main">Skip to main content</a>

<header class="site-header">
  <div class="container">
    <a class="brand" href="<?= url('index.php') ?>">
      <span class="brand-logo"><img src="<?= url('assets/img/logo.svg') ?>" alt=""></span>
      <span>
        <span class="brand-name">SJP2CD Repository</span><br>
        <span class="brand-sub">St. John Paul II College of Davao</span>
      </span>
    </a>

    <nav class="site-nav" aria-label="Primary">
      <a href="<?= url('index.php') ?>"<?= $cur('home') ?>>Home</a>
      <a href="<?= url('browse.php') ?>"<?= $cur('browse') ?>>Browse</a>
      <a href="<?= url('about.php') ?>"<?= $cur('about') ?>>About</a>
      <?php if (canDeposit()): ?>
      <a href="<?= url('submit.php') ?>"<?= $cur('submit') ?>>Deposit</a>
      <?php endif; ?>
    </nav>

    <div class="header-actions">
      <button class="btn btn-ghost btn-icon" data-theme-toggle type="button" aria-pressed="false" aria-label="Switch appearance"></button>
      <button class="btn btn-ghost btn-icon menu-toggle" data-mobile-toggle type="button" aria-expanded="false" aria-controls="mnav" aria-label="Open menu"><i data-ico="menu"></i></button>
      <?php if (isLoggedIn()): ?>
      <a class="btn btn-primary" href="<?= url('dashboard.php') ?>"><i data-ico="home" class="ico-sm"></i> Dashboard</a>
      <?php else: ?>
      <a class="btn btn-primary" href="<?= url('login.php') ?>"><i data-ico="lock" class="ico-sm"></i> Sign in</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="mobile-nav" id="mnav">
    <nav class="container" aria-label="Mobile">
      <a href="<?= url('index.php') ?>">Home</a>
      <a href="<?= url('browse.php') ?>">Browse</a>
      <a href="<?= url('about.php') ?>">About</a>
      <?php if (isLoggedIn()): ?>
      <?php if (canDeposit()): ?><a href="<?= url('submit.php') ?>">Deposit</a><?php endif; ?>
      <a href="<?= url('dashboard.php') ?>">Dashboard</a>
      <a href="<?= url('logout.php') ?>">Sign out</a>
      <?php else: ?>
      <a href="<?= url('login.php') ?>">Sign in</a>
      <a href="<?= url('register.php') ?>">Create an account</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<?php foreach (takeFlashes() as $f): ?>
<div class="container mt-4">
  <div class="alert alert-<?= e($f['type']) ?>" role="<?= $f['type'] === 'danger' ? 'alert' : 'status' ?>">
    <i data-ico="<?= $f['type'] === 'ok' ? 'checkcircle' : ($f['type'] === 'danger' ? 'alert' : 'info') ?>" class="ico-sm"></i>
    <span><?= $f['message'] ?></span>
  </div>
</div>
<?php endforeach; ?>
