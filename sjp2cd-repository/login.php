<?php
require_once __DIR__ . '/config/config.php';

if (isLoggedIn()) redirect('dashboard.php');

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'Enter both your email and password.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        /* A student, faculty or library account is only reachable through the
           college's own address. If one was created another way, it does not
           open here -- which is the rule the college asked for. */
        if ($user && in_array($user['role'], collegeRoles(), true) && !isInstitutionalEmail((string)$user['email'])) {
            $error = 'College accounts sign in with a ' . institutionalDomainList() . ' address. Ask the library if yours needs changing.';
            $user  = null;
        }

        /* Told only after the password checks out: saying "this account is
           waiting" to anyone who typed an address would confirm the address is
           registered. */
        if ($user && !(int)$user['is_active'] && password_verify($password, $user['password_hash'])) {
            $error = 'This account is not open yet. The College Library confirms college accounts before they can be used, so that only people at the college can read the full text.';
            $user  = null;
        }

        if ($user && password_verify($password, $user['password_hash'])) {
            signIn($pdo, $user);
            $to = $_SESSION['redirect_after_login'] ?? null;
            unset($_SESSION['redirect_after_login']);
            redirect($to ?: 'dashboard.php');
        }
        /* One message for both cases -- naming which half was wrong helps an
           attacker. It must not overwrite a more specific reason already set
           above, which is only ever shown once the password has checked out. */
        if ($error === '') {
            $error = 'That email and password do not match an account.';
        }
    }
}

$published = (int)$pdo->query("SELECT COUNT(*) FROM records WHERE status IN ('published','archived')")->fetchColumn();
$people    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1")->fetchColumn();
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
<title>Sign in — <?= APP_SHORT ?></title>
<link rel="icon" href="<?= url('assets/img/logo.svg') ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700&family=JetBrains+Mono:wght@400;600&display=swap">
<link rel="stylesheet" href="<?= url('assets/css/design-system.css') ?>?v=<?= @filemtime(ROOT_PATH . '/assets/css/design-system.css') ?: time() ?>">
</head>
<body>
<a class="skip-link" href="#main">Skip to main content</a>

<div class="auth">
<div class="auth-card">
  <section class="auth-art">
    <img class="auth-photo" src="<?= url('assets/img/campus.jpg') ?>" alt="" aria-hidden="true"
         width="1100" height="733" loading="eager" decoding="async">
    <span class="auth-wash" aria-hidden="true"></span>

    <a class="brand auth-brand" href="<?= url('index.php') ?>">
      <span class="brand-logo"><img src="<?= url('assets/img/logo.svg') ?>" alt=""></span>
      <span>
        <span class="brand-name">SJP2CD Repository</span><br>
        <span class="brand-sub">St. John Paul II College of Davao</span>
      </span>
    </a>

    <div class="auth-say">
      <p class="auth-motto">Ad Astra Per Aspera</p>
      <h2>Work that took a year to write should take a minute to find.</h2>
      <p class="auth-lede">Every deposit is described so it can be found, checksummed so it can be trusted, and packaged so it can outlive the software that made it.</p>
      <div class="auth-stats">
        <div><span class="k"><?= number_format($published) ?></span><span class="v">records published</span></div>
        <div><span class="k"><?= number_format($people) ?></span><span class="v">people using it</span></div>
      </div>
    </div>
  </section>
  <main class="auth-form" id="main">
    <div class="auth-box">
      <div class="row-between mb-8">
        <a class="btn btn-ghost btn-sm" href="<?= url('index.php') ?>"><i data-ico="arrowleft" class="ico-sm"></i> Back to the repository</a>
        <button class="btn btn-ghost btn-icon" data-theme-toggle type="button" aria-pressed="false" aria-label="Switch appearance"></button>
      </div>

      <h1 style="font-size:var(--fs-2xl)">Sign in</h1>
      <p class="muted mt-2 mb-8"><?= GOOGLE_ENABLED
            ? 'Use your college Google account.'
            : 'Use your college account.' ?></p>

      <?php foreach (takeFlashes() as $f): ?>
      <div class="alert alert-<?= e($f['type'] === 'ok' ? 'ok' : ($f['type'] === 'danger' ? 'danger' : ($f['type'] === 'warn' ? 'warn' : 'info'))) ?> mb-6" role="alert">
        <i data-ico="<?= $f['type'] === 'ok' ? 'check' : 'alert' ?>" class="ico-sm"></i><span><?= $f['message'] ?></span>
      </div>
      <?php endforeach; ?>

      <?php if ($error): ?>
      <div class="alert alert-danger mb-6" role="alert">
        <i data-ico="alert" class="ico-sm"></i><span><?= e($error) ?></span>
      </div>
      <?php endif; ?>

      <?php if (GOOGLE_ENABLED): ?>
      <!-- Only rendered when a client id is configured, so the page can never
           offer a button that does nothing. -->
      <a class="btn btn-primary btn-lg btn-block auth-google" href="<?= url('auth-google.php') ?>">
        <svg viewBox="0 0 18 18" width="18" height="18" aria-hidden="true" focusable="false">
          <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z"/>
          <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18z"/>
          <path fill="#FBBC05" d="M3.97 10.72a5.4 5.4 0 0 1 0-3.44V4.95H.96a9 9 0 0 0 0 8.1l3.01-2.33z"/>
          <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.46 3.44 1.35l2.58-2.58C13.46.9 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z"/>
        </svg>
        Continue with your <?= e(GOOGLE_HOSTED_DOMAIN) ?> account
      </a>
      <p class="hint center mt-3">Students, faculty and library staff sign in this way.</p>

      <div class="auth-or"><span>or</span></div>

      <p class="small muted mb-4">
        <strong>Visitor or library account.</strong> Use this only if your account was made
        with an address outside the college, or the library gave you a password.
      </p>
      <?php endif; ?>

      <form method="post" novalidate>
        <?= csrfField() ?>
        <div class="field">
          <label class="label" for="email"><?= GOOGLE_ENABLED ? 'Email' : 'College email' ?></label>
          <div class="input-icon">
            <i data-ico="mail"></i>
            <input class="input" id="email" name="email" type="email" required
                   autocomplete="username" inputmode="email"<?= GOOGLE_ENABLED ? '' : ' autofocus' ?>
                   placeholder="<?= GOOGLE_ENABLED ? 'you@example.com' : 'you@' . e(INSTITUTIONAL_DOMAIN) ?>" value="<?= e($email) ?>">
          </div>
        </div>

        <div class="field">
          <label class="label" for="password">Password</label>
          <div class="input-icon">
            <i data-ico="lock"></i>
            <input class="input" id="password" name="password" type="password" required
                   autocomplete="current-password" placeholder="••••••••" style="padding-right:52px">
            <button class="btn btn-ghost btn-icon btn-sm" type="button" id="pwToggle"
                    aria-pressed="false" aria-label="Show password"
                    style="position:absolute;right:6px;top:50%;transform:translateY(-50%)">
              <i data-ico="eye" class="ico-sm"></i>
            </button>
          </div>
          <span class="hint">Password managers and paste both work here.</span>
        </div>

        <button class="btn btn-primary btn-lg btn-block" type="submit">Sign in</button>
      </form>


      <p class="auth-alt">No account? <a href="<?= url('register.php') ?>">Create one</a>.</p>
      <p class="auth-help">
        Locked out? The College Library can reset your password —
        <span class="mono">repository@sjp2cd.edu.ph</span>
      </p>
    </div>
  </main>
</div>
</div>

<script src="<?= url('assets/js/icons.js') ?>"></script>
<script src="<?= url('assets/js/ui.js') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var pw = document.getElementById('password'), tog = document.getElementById('pwToggle');
  if (!pw || !tog) return;
  tog.addEventListener('click', function () {
    var showing = pw.type === 'text';
    pw.type = showing ? 'password' : 'text';
    tog.setAttribute('aria-pressed', String(!showing));
    tog.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    tog.innerHTML = window.Icons.svg(showing ? 'eye' : 'close', 'ico ico-sm');
    tog.firstChild.setAttribute('aria-hidden', 'true');
    pw.focus();
  });
});
</script>
</body>
</html>
