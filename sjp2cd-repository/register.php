<?php
require_once __DIR__ . '/config/config.php';

require_once ROOT_PATH . '/includes/metadata.php';

if (isLoggedIn()) redirect('dashboard.php');

$errors = [];
$in = ['name' => '', 'email' => '', 'department_id' => '', 'student_number' => '', 'adviser_id' => '', 'wants_faculty' => ''];

$departments = $pdo->query("SELECT id, code, name FROM departments WHERE is_active = 1 ORDER BY name")->fetchAll();
$advisers    = $pdo->query(
    "SELECT u.id, u.name, d.code FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     WHERE u.role = 'faculty' AND u.is_active = 1 ORDER BY u.name"
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    foreach ($in as $k => $_) { $in[$k] = trim((string)($_POST[$k] ?? '')); }
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['password_confirm'] ?? '');

    if ($in['name'] === '')                       $errors['name']     = 'Enter your full name.';
    if (!filter_var($in['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    if (strlen($password) < 8)                    $errors['password'] = 'Use at least 8 characters.';
    if ($password !== $confirm)                   $errors['password_confirm'] = 'Both passwords must match.';
    /* Who someone is, is settled by the address they can receive mail at, not
       by a box they tick. A college address makes a student account; anything
       else makes a visitor who can read the catalogue.

       Nobody registers as faculty. Faculty can deposit work into the permanent
       record of the college, so that has to be granted by the library rather
       than claimed -- otherwise anyone holding any college address, including
       every student, could take it. A registrant may ask; the library decides. */
    $domainRole = institutionalRoleFor($in['email']);
    $isCollege  = $domainRole !== null;
    $role       = $domainRole ?? 'guest';

    if ($isCollege && $in['department_id'] === '')
        $errors['department_id'] = 'Choose your department.';

    if (!$errors) {
        $dupe = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $dupe->execute([$in['email']]);
        if ($dupe->fetch()) {
            $errors['email'] = 'An account already uses that email. Sign in instead.';
        }
    }

    if (!$errors) {
        $wantsFaculty = $isCollege && $in['wants_faculty'] !== '';

        /* Typing a college address is not the same as holding one. Anyone can
           put someone else's @sjp2cd.edu.ph in this box, and downloading the
           college's research is exactly what that would buy them. So a college
           account starts switched off and the library turns it on once it can
           see the person is on the roll. A visitor account is active at once:
           it can only read what is already public, so there is nothing to gain
           by faking one. */
        $active = $isCollege ? 0 : 1;

        $pdo->prepare(
            "INSERT INTO users (name, email, password_hash, role, is_active, faculty_requested_at, department_id, student_number, adviser_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $in['name'], $in['email'], password_hash($password, PASSWORD_DEFAULT),
            $role, $active, $wantsFaculty ? date('Y-m-d H:i:s') : null,
            $in['department_id'] !== '' ? (int)$in['department_id'] : null,
            $in['student_number'] !== '' ? $in['student_number'] : null,
            $in['adviser_id'] !== '' ? (int)$in['adviser_id'] : null,
        ]);
        $newId = (int)$pdo->lastInsertId();
        premisAgentForUser($pdo, $newId, $in['name']);
        logActivity($pdo, $newId, 'Account created', $in['email']);

        if ($isCollege) {
            notifyLibrary($pdo, 'A college account is waiting to be confirmed',
                $in['name'] . ' (' . $in['email'] . ') registered'
                . ($in['student_number'] !== '' ? ', student number ' . $in['student_number'] : '')
                . ($wantsFaculty ? ', and asked to be recorded as faculty' : '') . '.',
                'account', null);
        }

        flash('ok', $role === 'guest'
            ? 'Your visitor account is ready. Sign in to search and read the catalogue; downloading needs a college account.'
            : 'Thank you. The College Library confirms college accounts before they open, so that only people at the college can read the full text. You will be able to sign in once they have.');
        redirect('login.php');
    }
}
require_once ROOT_PATH . '/includes/metadata.php';
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
<title>Create an account — <?= APP_SHORT ?></title>
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
      <h2>Your capstone belongs somewhere permanent.</h2>
      <p class="auth-lede">Students deposit their finished work and it goes to their adviser for approval. Faculty deposit their own research directly. Either way the library keeps it.</p>
    </div>
  </section>
  <main class="auth-form" id="main">
    <div class="auth-box">
      <div class="row-between mb-8">
        <a class="btn btn-ghost btn-sm" href="<?= url('index.php') ?>"><i data-ico="arrowleft" class="ico-sm"></i> Back to the repository</a>
        <button class="btn btn-ghost btn-icon" data-theme-toggle type="button" aria-pressed="false" aria-label="Switch appearance"></button>
      </div>

      <h1 style="font-size:var(--fs-2xl)">Create an account</h1>
      <p class="muted mt-2 mb-8"><?= GOOGLE_ENABLED
            ? 'At the college? Use Google. Everyone else, fill in the form.'
            : 'Use your college email so the library can verify you.' ?></p>

      <?php if (GOOGLE_ENABLED): ?>
      <!-- The college route comes first. Signing in with the college Google
           account creates the account and opens it at once: Google has already
           proved the address belongs to this person, which is what the library
           would otherwise have to confirm by hand. -->
      <a class="btn btn-primary btn-lg btn-block auth-google" href="<?= url('auth-google.php') ?>">
        <svg viewBox="0 0 18 18" width="18" height="18" aria-hidden="true" focusable="false">
          <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z"/>
          <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18z"/>
          <path fill="#FBBC05" d="M3.97 10.72a5.4 5.4 0 0 1 0-3.44V4.95H.96a9 9 0 0 0 0 8.1l3.01-2.33z"/>
          <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.46 3.44 1.35l2.58-2.58C13.46.9 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z"/>
        </svg>
        Continue with your <?= e(GOOGLE_HOSTED_DOMAIN) ?> account
      </a>
      <p class="hint center mt-3">Students, faculty and library staff: no form to fill in, and your account opens straight away.</p>

      <div class="auth-or"><span>or</span></div>

      <p class="small muted mb-6">
        <strong>Create a visitor account.</strong> For anyone outside the college. You can
        search and read the whole catalogue; downloading a file needs a college account.
      </p>
      <?php endif; ?>



              <?php foreach (takeFlashes() as $f): ?>
        <div class="alert alert-<?= e($f['type'] === 'ok' ? 'ok' : ($f['type'] === 'danger' ? 'danger' : ($f['type'] === 'warn' ? 'warn' : 'info'))) ?> mb-6" role="alert">
          <i data-ico="<?= $f['type'] === 'ok' ? 'check' : 'alert' ?>" class="ico-sm"></i><span><?= $f['message'] ?></span>
        </div>
        <?php endforeach; ?>

        <?php if ($errors): ?>
      <div class="alert alert-danger mb-6" role="alert" tabindex="-1" id="errsum">
        <i data-ico="alert" class="ico-sm"></i>
        <div class="grow">
          <strong><?= count($errors) === 1 ? 'One field needs fixing' : count($errors) . ' fields need fixing' ?></strong>
          <ul class="mt-2" style="list-style:disc;padding-left:18px">
            <?php foreach ($errors as $field => $m): ?>
            <li class="small"><a href="#<?= e($field) ?>" style="color:inherit"><?= e($m) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>
      <?php endif; ?>

      <form method="post" novalidate>
        <?= csrfField() ?>

        <div class="field">
          <label class="label" for="name">Full name</label>
          <input class="input" id="name" name="name" type="text" required autocomplete="name"
                 <?= isset($errors['name']) ? 'aria-invalid="true"' : '' ?>
                 value="<?= e($in['name']) ?>">
          <?php if (isset($errors['name'])): ?><p class="field-error"><i data-ico="alert" class="ico-sm"></i><span><?= e($errors['name']) ?></span></p><?php endif; ?>
        </div>

        <div class="field">
          <label class="label" for="email"><?= GOOGLE_ENABLED ? 'Email' : 'College email' ?></label>
          <input class="input" id="email" name="email" type="email" required autocomplete="email"
                 inputmode="email" placeholder="<?= GOOGLE_ENABLED ? 'you@example.com' : 'you@' . e(INSTITUTIONAL_DOMAIN) ?>"
                 <?= isset($errors['email']) ? 'aria-invalid="true"' : '' ?>
                 value="<?= e($in['email']) ?>">
          <?php if (isset($errors['email'])): ?><p class="field-error"><i data-ico="alert" class="ico-sm"></i><span><?= e($errors['email']) ?></span></p><?php endif; ?>
        </div>

        <div class="grid-2">
          <div class="field">
            <label class="label" for="password">Password</label>
            <input class="input" id="password" name="password" type="password" required
                   autocomplete="new-password" minlength="8"
                   <?= isset($errors['password']) ? 'aria-invalid="true"' : '' ?>>
            <span class="hint">At least 8 characters.</span>
            <?php if (isset($errors['password'])): ?><p class="field-error"><i data-ico="alert" class="ico-sm"></i><span><?= e($errors['password']) ?></span></p><?php endif; ?>
          </div>
          <div class="field">
            <label class="label" for="password_confirm">Repeat password</label>
            <input class="input" id="password_confirm" name="password_confirm" type="password" required
                   autocomplete="new-password" minlength="8"
                   <?= isset($errors['password_confirm']) ? 'aria-invalid="true"' : '' ?>>
            <?php if (isset($errors['password_confirm'])): ?><p class="field-error"><i data-ico="alert" class="ico-sm"></i><span><?= e($errors['password_confirm']) ?></span></p><?php endif; ?>
          </div>
        </div>

        <div class="alert alert-info mb-6" id="roleNote">
          <i data-ico="info" class="ico-sm"></i>
          <span>
            <?php if (GOOGLE_ENABLED): ?>
            <strong>Using a college address here?</strong> It works, but the library has to
            confirm the account before it opens. The Google button above does that instantly.
            Any other address gives you a visitor account.
            <?php else: ?>
            <strong>Use your college address</strong> to get a college account
            (<code><?= e(institutionalDomainList()) ?></code>). Any other address gives you a
            visitor account: you can search and read the whole catalogue, but downloading a
            file needs a college account.
            <?php endif; ?>
          </span>
        </div>

        <label class="check mb-6" id="facultyAsk" hidden>
          <input type="checkbox" name="wants_faculty" value="1" <?= $in['wants_faculty'] !== '' ? 'checked' : '' ?>>
          <span>I am a member of faculty. <span class="hint">The library confirms this before you can deposit work.</span></span>
        </label>

        <div id="collegeFields" hidden>
        <div class="field">
          <label class="label" for="department_id">Department</label>
          <select class="select" id="department_id" name="department_id"
                  <?= isset($errors['department_id']) ? 'aria-invalid="true"' : '' ?>>
            <option value="">Choose your department</option>
            <?php foreach ($departments as $d): ?>
            <option value="<?= (int)$d['id'] ?>" <?= (string)$in['department_id'] === (string)$d['id'] ? 'selected' : '' ?>>
              <?= e($d['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['department_id'])): ?><p class="field-error"><i data-ico="alert" class="ico-sm"></i><span><?= e($errors['department_id']) ?></span></p><?php endif; ?>
        </div>

        <div id="studentFields">
          <div class="field">
            <label class="label" for="student_number">Student number</label>
            <input class="input" id="student_number" name="student_number" type="text"
                   placeholder="2022-01847" value="<?= e($in['student_number']) ?>">
            <span class="hint">Optional, but it helps the library confirm you are on the roll.</span>
          </div>

          <div class="field">
            <label class="label" for="adviser_id">Your adviser <span class="subtle" style="font-weight:400">(optional)</span></label>
            <select class="select" id="adviser_id" name="adviser_id"
                    <?= isset($errors['adviser_id']) ? 'aria-invalid="true"' : '' ?>>
              <option value="">Choose the faculty member who supervises you</option>
              <?php foreach ($advisers as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= (string)$in['adviser_id'] === (string)$a['id'] ? 'selected' : '' ?>>
                <?= e($a['name']) ?><?= $a['code'] ? ' — ' . e($a['code']) : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
            <span class="hint">Optional. Choose them if you want to message them here; the library can set this later.</span>
            <?php if (isset($errors['adviser_id'])): ?><p class="field-error"><i data-ico="alert" class="ico-sm"></i><span><?= e($errors['adviser_id']) ?></span></p><?php endif; ?>
          </div>
        </div>
        </div>

        <button class="btn btn-primary btn-lg btn-block" type="submit">Create account</button>
      </form>

      <hr class="divider">
      <p class="small muted center">Already registered? <a href="<?= url('login.php') ?>">Sign in</a>.</p>
    </div>
  </main>
</div>
</div>

<script src="<?= url('assets/js/icons.js') ?>"></script>
<script src="<?= url('assets/js/ui.js') ?>"></script>
<script>
/* The address decides what kind of account this will be, so the form follows
   it as it is typed: college fields appear for a college address, and the
   faculty request only appears where it could apply. The server decides the
   same thing again from the address alone -- this is only to save the reader
   from filling in boxes that do not apply to them. */
document.addEventListener('DOMContentLoaded', function () {
  var email   = document.getElementById('email');
  var college = document.getElementById('collegeFields');
  var ask     = document.getElementById('facultyAsk');
  var note    = document.getElementById('roleNote');
  var dept    = document.getElementById('department_id');
  var DOMAINS = <?= json_encode(array_keys(INSTITUTIONAL_DOMAINS)) ?>;

  function apply() {
    var at = email.value.trim().toLowerCase().lastIndexOf('@');
    var isCollege = at > -1 && DOMAINS.indexOf(email.value.trim().toLowerCase().slice(at + 1)) > -1;
    if (college) college.hidden = !isCollege;
    if (ask)     ask.hidden     = !isCollege;
    if (dept)    dept.required  = isCollege;
    if (note)    note.classList.toggle('alert-ok', isCollege);
  }

  if (email) {
    email.addEventListener('input', apply);
    email.addEventListener('blur', apply);
    apply();
  }

  var sum = document.getElementById('errsum');
  if (sum) sum.focus();
});
</script>
</body>
</html>
