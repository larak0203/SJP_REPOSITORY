<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$uid = currentUserId();
$me  = currentUser($pdo);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $act = (string)($_POST['action'] ?? '');

    /* ---------------------------------------------------------- details --- */
    if ($act === 'details') {
        $name  = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $dept  = (int)($_POST['department_id'] ?? 0) ?: null;
        $sNum  = trim((string)($_POST['student_number'] ?? ''));

        if ($name === '')                              $errors['name']  = 'Your name cannot be blank.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'That is not a valid email address.';
        else {
            $dup = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
            $dup->execute([$email, $uid]);
            if ($dup->fetch()) $errors['email'] = 'Another account already uses that address.';
        }

        if (!$errors) {
            $pdo->prepare(
                "UPDATE users SET name = ?, email = ?, department_id = ?, student_number = ? WHERE id = ?"
            )->execute([$name, $email, $dept, $sNum ?: null, $uid]);
            $_SESSION['name']  = $name;   // keep the sidebar and greeting in step
            $_SESSION['email'] = $email;
            logActivity($pdo, $uid, 'updated their profile');
            flash('ok', 'Your details are saved.');
            redirect('profile.php');
        }
    }

    /* --------------------------------------------------------- password --- */
    if ($act === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (!password_verify($current, (string)$me['password_hash'])) {
            $errors['current_password'] = 'That is not your current password.';
        }
        if (strlen($new) < 8) {
            $errors['new_password'] = 'Use at least 8 characters.';
        } elseif ($new === $current) {
            $errors['new_password'] = 'Choose something different from your current password.';
        }
        if ($new !== $confirm) {
            $errors['confirm_password'] = 'The two passwords do not match.';
        }

        if (!$errors) {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                ->execute([password_hash($new, PASSWORD_DEFAULT), $uid]);
            session_regenerate_id(true);
            logActivity($pdo, $uid, 'changed their password');
            flash('ok', 'Password changed. Use the new one next time you sign in.');
            redirect('profile.php');
        }
    }

    /* ----------------------------------------------------------- avatar --- */
    if ($act === 'avatar') {
        $f = $_FILES['avatar'] ?? null;

        if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
            $errors['avatar'] = 'Choose an image first.';
        } elseif ($f['error'] !== UPLOAD_ERR_OK) {
            $errors['avatar'] = 'The upload did not complete. Try again.';
        } elseif ($f['size'] > 2 * 1024 * 1024) {
            $errors['avatar'] = 'Keep it under 2 MB.';
        } else {
            $info = @getimagesize($f['tmp_name']);
            $ext  = match ($info['mime'] ?? '') {
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                default      => null,
            };
            if (!$ext) {
                $errors['avatar'] = 'Use a JPEG, PNG or WebP image.';
            } else {
                if (!is_dir(AVATAR_PATH)) @mkdir(AVATAR_PATH, 0775, true);
                $stored = $uid . '_' . bin2hex(random_bytes(6)) . '.' . $ext;

                if (move_uploaded_file($f['tmp_name'], AVATAR_PATH . '/' . $stored)) {
                    if (!empty($me['avatar']) && is_file(AVATAR_PATH . '/' . $me['avatar'])) {
                        @unlink(AVATAR_PATH . '/' . $me['avatar']);
                    }
                    $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?")->execute([$stored, $uid]);
                    flash('ok', 'New picture saved.');
                    redirect('profile.php');
                }
                $errors['avatar'] = 'The file could not be stored. Check the uploads folder is writable.';
            }
        }
    }

    /* ----------------------------------------------------- remove avatar --- */
    if ($act === 'remove_avatar') {
        if (!empty($me['avatar']) && is_file(AVATAR_PATH . '/' . $me['avatar'])) {
            @unlink(AVATAR_PATH . '/' . $me['avatar']);
        }
        $pdo->prepare("UPDATE users SET avatar = NULL WHERE id = ?")->execute([$uid]);
        flash('ok', 'Picture removed. Your initials are shown instead.');
        redirect('profile.php');
    }

    $me = currentUser($pdo);   // reflect anything that did save
}

$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();

$stats = $pdo->prepare(
    "SELECT COUNT(*) deposits,
            SUM(status='published') published,
            COALESCE(SUM(views),0) views,
            COALESCE(SUM(downloads),0) downloads
     FROM records WHERE submitted_by = ?"
);
$stats->execute([$uid]);
$my = $stats->fetch();

$activity = $pdo->prepare(
    "SELECT * FROM activity_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 10"
);
$activity->execute([$uid]);
$activityRows = $activity->fetchAll();

$avatarUrl = (!empty($me['avatar']) && is_file(AVATAR_PATH . '/' . $me['avatar']))
    ? url('uploads/avatars/' . rawurlencode((string)$me['avatar']))
    : null;

$pageLabel = 'Profile';
$pageSub   = 'Your details, your picture and your password.';
include ROOT_PATH . '/templates/layout/app_header.php';
?>

<?php if ($errors): ?>
<div class="alert alert-danger mb-6" role="alert">
  <i data-ico="alert" class="ico-sm"></i>
  <span>Some changes were not saved. The problems are marked below.</span>
</div>
<?php endif; ?>

<div class="workspace">
  <div>
    <section class="card mb-6">
      <h2 class="panel-title mb-4">Your details</h2>
      <form method="post" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="details">

        <div class="grid-2">
          <div class="field">
            <label class="label" for="name">Full name <span class="req">*</span></label>
            <input class="input" id="name" name="name" type="text" required
                   value="<?= e($_POST['name'] ?? $me['name']) ?>"
                   <?= isset($errors['name']) ? 'aria-invalid="true"' : '' ?>>
            <?php if (isset($errors['name'])): ?>
            <p class="field-error"><i data-ico="alert" class="ico-sm"></i><span><?= e($errors['name']) ?></span></p>
            <?php endif; ?>
          </div>

          <div class="field">
            <label class="label" for="email">Email <span class="req">*</span></label>
            <input class="input" id="email" name="email" type="email" required
                   value="<?= e($_POST['email'] ?? $me['email']) ?>"
                   <?= isset($errors['email']) ? 'aria-invalid="true"' : '' ?>>
            <?php if (isset($errors['email'])): ?>
            <p class="field-error"><i data-ico="alert" class="ico-sm"></i><span><?= e($errors['email']) ?></span></p>
            <?php else: ?>
            <p class="hint">This is what you sign in with.</p>
            <?php endif; ?>
          </div>

          <div class="field">
            <label class="label" for="department_id">Department</label>
            <select class="select" id="department_id" name="department_id">
              <option value="">Not stated</option>
              <?php foreach ($departments as $d): ?>
              <option value="<?= (int)$d['id'] ?>" <?= (int)$me['department_id'] === (int)$d['id'] ? 'selected' : '' ?>>
                <?= e($d['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <p class="hint">Used to file your deposits under the right programme.</p>
          </div>

          <?php if (isStudent()): ?>
          <div class="field">
            <label class="label" for="student_number">Student number</label>
            <input class="input mono" id="student_number" name="student_number" type="text"
                   value="<?= e($_POST['student_number'] ?? (string)$me['student_number']) ?>"
                   placeholder="2022-00123">
            <p class="hint">Appears to your adviser when they review your work.</p>
          </div>
          <?php endif; ?>
        </div>

        <div class="row mt-4" style="gap:8px">
          <button class="btn btn-primary" type="submit">Save details</button>
        </div>
      </form>
    </section>

    <section class="card mb-6">
      <h2 class="panel-title mb-2">Change your password</h2>
      <p class="small muted mb-4">You stay signed in here; other sessions are ended.</p>
      <form method="post" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="password">

        <div class="field">
          <label class="label" for="current_password">Current password <span class="req">*</span></label>
          <input class="input" id="current_password" name="current_password" type="password"
                 autocomplete="current-password" required
                 <?= isset($errors['current_password']) ? 'aria-invalid="true"' : '' ?>>
          <?php if (isset($errors['current_password'])): ?>
          <p class="field-error"><i data-ico="alert" class="ico-sm"></i><span><?= e($errors['current_password']) ?></span></p>
          <?php endif; ?>
        </div>

        <div class="grid-2">
          <div class="field">
            <label class="label" for="new_password">New password <span class="req">*</span></label>
            <input class="input" id="new_password" name="new_password" type="password"
                   autocomplete="new-password" minlength="8" required
                   <?= isset($errors['new_password']) ? 'aria-invalid="true"' : '' ?>>
            <?php if (isset($errors['new_password'])): ?>
            <p class="field-error"><i data-ico="alert" class="ico-sm"></i><span><?= e($errors['new_password']) ?></span></p>
            <?php else: ?>
            <p class="hint">At least 8 characters.</p>
            <?php endif; ?>
          </div>

          <div class="field">
            <label class="label" for="confirm_password">Repeat it <span class="req">*</span></label>
            <input class="input" id="confirm_password" name="confirm_password" type="password"
                   autocomplete="new-password" required
                   <?= isset($errors['confirm_password']) ? 'aria-invalid="true"' : '' ?>>
            <?php if (isset($errors['confirm_password'])): ?>
            <p class="field-error"><i data-ico="alert" class="ico-sm"></i><span><?= e($errors['confirm_password']) ?></span></p>
            <?php endif; ?>
          </div>
        </div>

        <button class="btn btn-primary mt-4" type="submit">Change password</button>
      </form>
    </section>

    <?php if ($activityRows): ?>
    <section class="card">
      <h2 class="panel-title mb-4">What you have done here</h2>
      <ol class="timeline">
        <?php foreach ($activityRows as $a): ?>
        <li class="tl-item">
          <span class="tl-dot" aria-hidden="true"></span>
          <p class="small">You <?= e($a['action']) ?><?php
            if ($a['details']) echo ' <span class="subtle">— ' . e(mb_strimwidth((string)$a['details'], 0, 70, '…')) . '</span>'; ?></p>
          <p class="xs subtle mt-2"><?= humanDate($a['created_at'], 'j M Y, g:i a') ?></p>
        </li>
        <?php endforeach; ?>
      </ol>
    </section>
    <?php endif; ?>
  </div>

  <aside class="rail">
    <section class="card center">
      <?php if ($avatarUrl): ?>
      <img class="avatar avatar-xl" src="<?= e($avatarUrl) ?>" alt="Your profile picture"
           style="object-fit:cover;margin-inline:auto">
      <?php else: ?>
      <span class="avatar avatar-xl" aria-hidden="true" style="margin-inline:auto">
        <?= e(mb_strtoupper(mb_substr((string)$me['name'], 0, 2))) ?>
      </span>
      <?php endif; ?>

      <h2 class="mt-4" style="font-size:var(--fs-lg)"><?= e($me['name']) ?></h2>
      <p class="small subtle"><?= e($me['email']) ?></p>
      <div class="row mt-3" style="gap:8px;justify-content:center">
        <span class="badge <?= isAdmin() ? 'badge-gold' : (isFaculty() ? 'badge-violet' : 'badge-brand') ?>">
          <?= isAdmin() ? 'Library staff' : ucfirst(currentRole()) ?>
        </span>
        <?php if ($me['department_name'] ?? null): ?>
        <span class="badge"><?= e($me['department_name']) ?></span>
        <?php endif; ?>
      </div>

      <form method="post" enctype="multipart/form-data" class="mt-6">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="avatar">
        <div class="field">
          <label class="label" for="avatar">Profile picture</label>
          <input class="input" id="avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp"
                 <?= isset($errors['avatar']) ? 'aria-invalid="true"' : '' ?>>
          <?php if (isset($errors['avatar'])): ?>
          <p class="field-error"><i data-ico="alert" class="ico-sm"></i><span><?= e($errors['avatar']) ?></span></p>
          <?php else: ?>
          <p class="hint">JPEG, PNG or WebP, up to 2 MB.</p>
          <?php endif; ?>
        </div>
        <button class="btn btn-outline btn-block btn-sm" type="submit">Upload picture</button>
      </form>

      <?php if ($avatarUrl): ?>
      <form method="post" class="mt-2">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="remove_avatar">
        <button class="btn btn-ghost btn-block btn-sm" type="submit">Remove picture</button>
      </form>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2 class="panel-title mb-4">Your contribution</h2>
      <table class="meta-table">
        <tr><th scope="row">Deposits</th><td><?= number_format((int)$my['deposits']) ?></td></tr>
        <tr><th scope="row">Published</th><td><?= number_format((int)$my['published']) ?></td></tr>
        <tr><th scope="row">Views</th><td><?= number_format((int)$my['views']) ?></td></tr>
        <tr><th scope="row">Downloads</th><td><?= number_format((int)$my['downloads']) ?></td></tr>
        <tr><th scope="row">Member since</th><td><?= humanDate($me['created_at']) ?></td></tr>
      </table>
      <a class="btn btn-outline btn-sm btn-block mt-4" href="<?= url('my-work.php') ?>">See my deposits</a>
    </section>

    <section class="card">
      <h2 class="panel-title mb-2">Account safety</h2>
      <ul class="std-list">
        <li>Passwords are stored as one-way hashes, never as text.</li>
        <li>Your session id is reissued whenever you sign in or change your password.</li>
        <li>Every form here is protected against cross-site request forgery.</li>
      </ul>
      <a class="btn btn-ghost btn-sm btn-block mt-4" href="<?= url('logout.php') ?>">
        <i data-ico="logout" class="ico-sm"></i> Sign out
      </a>
    </section>
  </aside>
</div>

<?php include ROOT_PATH . '/templates/layout/app_footer.php'; ?>
