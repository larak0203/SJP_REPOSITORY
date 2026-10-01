<?php
require_once __DIR__ . '/config/config.php';
requireRole('admin');

$meId = currentUserId();

/* ------------------------------------------------------------ actions --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $id  = (int)($_POST['id'] ?? 0);
    $act = (string)($_POST['action'] ?? '');

    $s = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $s->execute([$id]);
    $user = $s->fetch();

    if (!$user) {
        flash('danger', 'That account no longer exists.');
        redirect('manage-users.php');
    }
    if ($id === $meId && in_array($act, ['deactivate', 'role'], true)) {
        flash('warn', 'You cannot change your own role or lock yourself out.');
        redirect('manage-users.php');
    }

    switch ($act) {
        case 'role':
            $role = (string)($_POST['role'] ?? '');
            if (!in_array($role, ['guest', 'student', 'faculty', 'admin'], true)) {
                flash('danger', 'Unknown role.'); break;
            }
            /* A college role means college rights, so it needs a college
               address. Granting one to a Gmail account would create an account
               that cannot sign in, since login refuses exactly that. */
            if (in_array($role, collegeRoles(), true) && !isInstitutionalEmail((string)$user['email'])) {
                flash('danger', e($user['name']) . ' uses ' . e($user['email'])
                    . '. A student, faculty or library account needs a ' . institutionalDomainList() . ' address.');
                break;
            }
            /* Faculty and admins do not have advisers. */
            $pdo->prepare("UPDATE users SET role = ?, faculty_requested_at = NULL,
                                  adviser_id = IF(? = 'student', adviser_id, NULL) WHERE id = ?")
                ->execute([$role, $role, $id]);
            logActivity($pdo, $meId, 'changed a role', $user['name'] . ' → ' . $role);
            notify($pdo, $id, 'Your role changed',
                $role === 'faculty'
                    ? 'The library has confirmed you as faculty. You can now deposit work.'
                    : 'You are now recorded as ' . $role . '.', 'account');
            flash('ok', e($user['name']) . ' is now <strong>' . e($role) . '</strong>.');
            break;

        case 'decline_faculty':
            $pdo->prepare("UPDATE users SET faculty_requested_at = NULL WHERE id = ?")->execute([$id]);
            logActivity($pdo, $meId, 'declined a faculty request', $user['name']);
            notify($pdo, $id, 'About your faculty request',
                'The library did not confirm faculty access for this account. Ask the College Library if you think this is a mistake.', 'account');
            flash('ok', 'Request cleared.');
            break;

        case 'adviser':
            $adv = (int)($_POST['adviser_id'] ?? 0) ?: null;
            $pdo->prepare("UPDATE users SET adviser_id = ? WHERE id = ?")->execute([$adv, $id]);
            logActivity($pdo, $meId, 'assigned an adviser', $user['name']);
            flash('ok', 'Adviser updated for ' . e($user['name']) . '.');
            break;

        case 'deactivate':
            /* Marked as a decision, so an account the library closed is never
               reopened by a Google sign-in the way a pending one is. */
            $pdo->prepare("UPDATE users SET is_active = 0, deactivated_at = NOW() WHERE id = ?")->execute([$id]);
            logActivity($pdo, $meId, 'deactivated an account', $user['name']);
            flash('ok', e($user['name']) . ' can no longer sign in. Their deposits are untouched.');
            break;

        case 'activate':
            $pdo->prepare("UPDATE users SET is_active = 1, deactivated_at = NULL WHERE id = ?")->execute([$id]);
            notify($pdo, $id, 'Your account is open',
                'The College Library has confirmed your account. You can sign in and read the collection.', 'account');
            logActivity($pdo, $meId, 'reactivated an account', $user['name']);
            flash('ok', e($user['name']) . ' can sign in again.');
            break;

        case 'department':
            $dept = (int)($_POST['department_id'] ?? 0) ?: null;
            $pdo->prepare("UPDATE users SET department_id = ? WHERE id = ?")->execute([$dept, $id]);
            flash('ok', 'Department updated for ' . e($user['name']) . '.');
            break;

        default:
            flash('warn', 'Nothing to do.');
    }
    redirect('manage-users.php');
}

/* ------------------------------------------------------------- listing --- */
$q    = trim((string)($_GET['q'] ?? ''));
$role = (string)($_GET['role'] ?? '');

$where = ['1=1']; $params = [];
if ($q !== '') {
    $where[] = "(u.name LIKE ? OR u.email LIKE ? OR u.student_number LIKE ?)";
    array_push($params, "%$q%", "%$q%", "%$q%");
}
if (in_array($role, ['guest', 'student', 'faculty', 'admin'], true)) { $where[] = "u.role = ?"; $params[] = $role; }

$stmt = $pdo->prepare(
    "SELECT u.*, d.name AS department_name, a.name AS adviser_name,
            (SELECT COUNT(*) FROM records r WHERE r.submitted_by = u.id) deposits,
            (SELECT COUNT(*) FROM records r WHERE r.submitted_by = u.id AND r.status='published') published,
            (SELECT COUNT(*) FROM records r WHERE r.adviser_id = u.id AND r.status IN ('submitted','under_review')) advising
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     LEFT JOIN users a       ON a.id = u.adviser_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY u.is_active, u.faculty_requested_at IS NULL, FIELD(u.role,'admin','faculty','student','guest'), u.name"
);
$stmt->execute($params);
$people = $stmt->fetchAll();

$roleCounts = array_column(
    $pdo->query("SELECT role, COUNT(*) n FROM users GROUP BY role")->fetchAll(), 'n', 'role'
);
$advisers    = $pdo->query("SELECT id, name FROM users WHERE role IN ('faculty','admin') AND is_active = 1 ORDER BY name")->fetchAll();
$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name")->fetchAll();
$inactive    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 0")->fetchColumn();

$pageLabel = 'People';
$pageSub   = 'Who can deposit, who reviews, and who publishes.';
include ROOT_PATH . '/templates/layout/app_header.php';
?>

<section class="stat-row mb-8">
  <article data-tilt class="stat stat-blue">
    <span class="stat-ico"><i data-ico="users"></i></span>
    <span class="stat-value"><?= number_format((int)($roleCounts['student'] ?? 0)) ?></span>
    <span class="stat-label">Students</span>
    <span class="stat-foot">Deposit work for adviser approval</span>
  </article>
  <article data-tilt class="stat stat-mint">
    <span class="stat-ico"><i data-ico="cap"></i></span>
    <span class="stat-value"><?= number_format((int)($roleCounts['faculty'] ?? 0)) ?></span>
    <span class="stat-label">Faculty</span>
    <span class="stat-foot">Review submissions and self-archive</span>
  </article>
  <article data-tilt class="stat stat-gold">
    <span class="stat-ico"><i data-ico="shieldcheck"></i></span>
    <span class="stat-value"><?= number_format((int)($roleCounts['admin'] ?? 0)) ?></span>
    <span class="stat-label">Library staff</span>
    <span class="stat-foot">Publish, archive and audit</span>
  </article>
  <article data-tilt class="stat stat-lilac">
    <span class="stat-ico"><i data-ico="lock"></i></span>
    <span class="stat-value"><?= number_format($inactive) ?></span>
    <span class="stat-label">Awaiting confirmation</span>
    <span class="stat-foot">Cannot sign in; deposits are kept</span>
  </article>
</section>

<form class="card card-tight mb-6" method="get" role="search">
  <div class="row wrap" style="gap:var(--sp-3)">
    <div class="input-icon grow" style="min-width:240px">
      <i data-ico="search"></i>
      <label class="sr-only" for="q">Search people</label>
      <input class="input" id="q" name="q" type="search" value="<?= e($q) ?>"
             placeholder="Name, email or student number" autocomplete="off">
    </div>
    <label class="sr-only" for="role">Role</label>
    <select class="select" id="role" name="role" style="width:auto;min-width:170px" onchange="this.form.submit()">
      <option value="">Every role</option>
      <option value="student" <?= $role === 'student' ? 'selected' : '' ?>>Students</option>
      <option value="guest"   <?= $role === 'guest' ? 'selected' : '' ?>>Visitors</option>
      <option value="faculty" <?= $role === 'faculty' ? 'selected' : '' ?>>Faculty</option>
      <option value="admin"   <?= $role === 'admin' ? 'selected' : '' ?>>Library staff</option>
    </select>
    <button class="btn btn-primary" type="submit">Filter</button>
    <?php if ($q !== '' || $role !== ''): ?>
    <a class="btn btn-ghost" href="<?= url('manage-users.php') ?>">Clear</a>
    <?php endif; ?>
  </div>
</form>

<?php if (!$people): ?>
<div class="card"><div class="empty">
  <i data-ico="users" class="ico-xl"></i>
  <h4>Nobody matches</h4>
  <p class="mt-2">Try a different name, or clear the filter.</p>
</div></div>
<?php else: ?>

<div class="stack">
<?php foreach ($people as $p): $self = (int)$p['id'] === $meId; ?>
<article class="card <?= (int)$p['is_active'] ? '' : 'card-flat' ?>">
  <div class="row wrap" style="gap:var(--sp-4);align-items:flex-start">
    <?php if (!empty($p['avatar']) && is_file(AVATAR_PATH . '/' . $p['avatar'])): ?>
    <img class="avatar avatar-lg" src="<?= url('uploads/avatars/' . rawurlencode((string)$p['avatar'])) ?>" alt="" style="object-fit:cover">
    <?php else: ?>
    <span class="avatar avatar-lg <?= $p['role'] === 'admin' ? 'avatar-gold' : ($p['role'] === 'faculty' ? 'avatar-violet' : ($p['role'] === 'guest' ? '' : 'avatar-mint')) ?>" aria-hidden="true">
      <?= e(mb_strtoupper(mb_substr((string)$p['name'], 0, 2))) ?>
    </span>
    <?php endif; ?>

    <div class="grow" style="min-width:220px">
      <div class="row wrap mb-2" style="gap:8px">
        <h2 style="font-size:var(--fs-lg)"><?= e($p['name']) ?></h2>
        <span class="badge <?= $p['role'] === 'admin' ? 'badge-gold' : ($p['role'] === 'faculty' ? 'badge-violet' : 'badge-brand') ?>">
          <?= $p['role'] === 'admin' ? 'Library' : ($p['role'] === 'guest' ? 'Visitor' : e(ucfirst($p['role']))) ?>
        </span>
        <?php if ($self): ?><span class="badge">You</span><?php endif; ?>
        <?php if (!(int)$p['is_active']): ?>
        <span class="badge <?= empty($p['deactivated_at']) ? 'badge-warn' : 'badge-danger' ?>">
          <i data-ico="<?= empty($p['deactivated_at']) ? 'clock' : 'xcircle' ?>" class="ico-sm"></i>
          <?= empty($p['deactivated_at']) ? 'Waiting to be confirmed' : 'Closed by the library' ?>
        </span>
        <?php endif; ?>
      </div>

      <div class="result-meta">
        <span><i data-ico="mail" class="ico-sm"></i> <?= e($p['email']) ?></span>
        <?php if ($p['student_number']): ?><span><i data-ico="idcard" class="ico-sm"></i> <?= e($p['student_number']) ?></span><?php endif; ?>
        <span><i data-ico="cap" class="ico-sm"></i> <?= e($p['department_name'] ?: 'No department') ?></span>
        <?php if ($p['role'] === 'student' && $p['adviser_name']): ?>
        <span><i data-ico="user" class="ico-sm"></i> Advised by <?= e($p['adviser_name']) ?></span>
        <?php endif; ?>
        <span><i data-ico="clock" class="ico-sm"></i> <?= $p['last_login_at'] ? 'Seen ' . timeAgo($p['last_login_at']) : 'Never signed in' ?></span>
      </div>

      <div class="row wrap mt-3" style="gap:8px">
        <span class="chip chip-static"><?= (int)$p['deposits'] ?> deposit<?= (int)$p['deposits'] === 1 ? '' : 's' ?></span>
        <span class="chip chip-static"><?= (int)$p['published'] ?> published</span>
        <?php if ((int)$p['advising'] > 0): ?>
        <span class="chip chip-static"><i data-ico="checkcircle" class="ico-sm"></i> <?= (int)$p['advising'] ?> awaiting their review</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="divider" style="margin:var(--sp-5) 0"></div>

  <div class="row wrap" style="gap:var(--sp-4);align-items:flex-end">
    <form method="post" class="row" style="gap:6px;align-items:flex-end">
      <?= csrfField() ?>
      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <input type="hidden" name="action" value="role">
      <span>
        <label class="label" for="role<?= (int)$p['id'] ?>">Role</label>
        <select class="select" id="role<?= (int)$p['id'] ?>" name="role" style="width:auto;min-width:150px" <?= $self ? 'disabled' : '' ?>>
          <option value="guest"   <?= $p['role'] === 'guest' ? 'selected' : '' ?>>Visitor (read only)</option>
          <option value="student" <?= $p['role'] === 'student' ? 'selected' : '' ?>>Student</option>
          <option value="faculty" <?= $p['role'] === 'faculty' ? 'selected' : '' ?>>Faculty</option>
          <option value="admin"   <?= $p['role'] === 'admin' ? 'selected' : '' ?>>Library staff</option>
        </select>
      </span>
      <button class="btn btn-ghost btn-sm" type="submit" <?= $self ? 'disabled' : '' ?>>Set role</button>
    </form>

    <?php if (!empty($p['faculty_requested_at'])): ?>
    <form method="post" class="row" style="gap:6px;align-items:flex-end">
      <?= csrfField() ?>
      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <input type="hidden" name="action" value="decline_faculty">
      <span class="badge badge-warn"><i data-ico="clock" class="ico-sm"></i> Asked to be faculty <?= timeAgo($p['faculty_requested_at']) ?></span>
      <button class="btn btn-ghost btn-sm" type="submit">Decline</button>
    </form>
    <?php endif; ?>

    <form method="post" class="row" style="gap:6px;align-items:flex-end">
      <?= csrfField() ?>
      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <input type="hidden" name="action" value="department">
      <span>
        <label class="label" for="dept<?= (int)$p['id'] ?>">Department</label>
        <select class="select" id="dept<?= (int)$p['id'] ?>" name="department_id" style="width:auto;min-width:180px">
          <option value="">None</option>
          <?php foreach ($departments as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= (int)$p['department_id'] === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </span>
      <button class="btn btn-ghost btn-sm" type="submit">Set</button>
    </form>

    <?php if ($p['role'] === 'student'): ?>
    <form method="post" class="row" style="gap:6px;align-items:flex-end">
      <?= csrfField() ?>
      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <input type="hidden" name="action" value="adviser">
      <span>
        <label class="label" for="adv<?= (int)$p['id'] ?>">Adviser</label>
        <select class="select" id="adv<?= (int)$p['id'] ?>" name="adviser_id" style="width:auto;min-width:190px">
          <option value="">Not assigned</option>
          <?php foreach ($advisers as $a): ?>
          <option value="<?= (int)$a['id'] ?>" <?= (int)$p['adviser_id'] === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </span>
      <button class="btn btn-ghost btn-sm" type="submit">Assign</button>
    </form>
    <?php endif; ?>

    <div class="row wrap" style="margin-left:auto;gap:8px">
      <a class="btn btn-ghost btn-sm" href="<?= url('messages.php?to=' . (int)$p['id']) ?>"><i data-ico="mail" class="ico-sm"></i> Message</a>
      <?php if (!$self): ?>
      <form method="post" style="display:contents"
            onsubmit="return confirm(<?= (int)$p['is_active'] ? "'Deactivate this account? They will not be able to sign in.'" : "'Confirm this person is at the college and open their account?'" ?>)">
        <?= csrfField() ?>
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <input type="hidden" name="action" value="<?= (int)$p['is_active'] ? 'deactivate' : 'activate' ?>">
        <button class="btn btn-ghost btn-sm" type="submit" style="<?= (int)$p['is_active'] ? 'color:var(--danger)' : '' ?>">
          <?= (int)$p['is_active'] ? 'Deactivate' : 'Confirm this account' ?>
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</article>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php include ROOT_PATH . '/templates/layout/app_footer.php'; ?>
