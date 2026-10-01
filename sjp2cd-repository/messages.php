<?php
require_once __DIR__ . '/config/config.php';
requireLogin();
if (isGuest()) { flash('info', 'Messaging is for college accounts. Your account is for reading the catalogue.'); redirect('browse.php'); }

$uid = currentUserId();
$me  = currentUser($pdo);

/* Who this person can reasonably talk to:
   students ↔ their adviser and the library; faculty ↔ their advisees, colleagues
   and the library; library ↔ everyone. */
/* Native prepares are on, so every placeholder is named once and only once. */
$bind = [];

if (isAdmin()) {
    $reach = "id <> :self AND is_active = 1";
} elseif (isFaculty()) {
    /* Colleagues, the library, and the students this person advises. */
    $reach = "id <> :self AND is_active = 1 AND (role IN ('admin','faculty') OR adviser_id = :advisee)";
    $bind[':advisee'] = $uid;
} else {
    $reach = "id <> :self AND is_active = 1 AND (role = 'admin' OR id = :adviser)";
    $bind[':adviser'] = (int)($me['adviser_id'] ?? 0);
}
$bind[':self'] = $uid;

$c = $pdo->prepare(
    "SELECT c.*, d.name AS department_name,
            (SELECT COUNT(*) FROM messages m
              WHERE m.sender_id = c.id AND m.receiver_id = :me1 AND m.is_read = 0) unread,
            (SELECT m2.body FROM messages m2
              WHERE (m2.sender_id = c.id AND m2.receiver_id = :me2)
                 OR (m2.sender_id = :me3 AND m2.receiver_id = c.id)
              ORDER BY m2.id DESC LIMIT 1) last_body,
            (SELECT m3.created_at FROM messages m3
              WHERE (m3.sender_id = c.id AND m3.receiver_id = :me4)
                 OR (m3.sender_id = :me5 AND m3.receiver_id = c.id)
              ORDER BY m3.id DESC LIMIT 1) last_at
     FROM (SELECT id, name, role, avatar, department_id, last_login_at
             FROM users WHERE $reach) c
     LEFT JOIN departments d ON d.id = c.department_id
     ORDER BY (last_at IS NULL), last_at DESC, c.name"
);
$c->execute($bind + [':me1' => $uid, ':me2' => $uid, ':me3' => $uid, ':me4' => $uid, ':me5' => $uid]);
$contacts = $c->fetchAll();

$withId = (int)($_GET['to'] ?? 0);
$active = null;
foreach ($contacts as $p) { if ((int)$p['id'] === $withId) { $active = $p; break; } }
if (!$active && $contacts && !$withId) { $active = $contacts[0]; $withId = (int)$active['id']; }
if ($withId && !$active) {
    flash('warn', 'You cannot start a conversation with that person.');
    redirect('messages.php');
}

$history = [];
if ($active) {
    $h = $pdo->prepare(
        "SELECT id, sender_id, body, is_read, created_at FROM messages
         WHERE (sender_id = :me AND receiver_id = :them)
            OR (sender_id = :them2 AND receiver_id = :me2)
         ORDER BY id ASC LIMIT 300"
    );
    $h->execute([':me' => $uid, ':them' => $withId, ':them2' => $withId, ':me2' => $uid]);
    $history = $h->fetchAll();

    $pdo->prepare("UPDATE messages SET is_read = 1, read_at = NOW()
                   WHERE receiver_id = ? AND sender_id = ? AND is_read = 0")->execute([$uid, $withId]);
}
$lastId = $history ? (int)end($history)['id'] : 0;

function avatarFor(array $p): string {
    if (!empty($p['avatar']) && is_file(AVATAR_PATH . '/' . $p['avatar'])) {
        return '<img class="avatar" src="' . e(url('uploads/avatars/' . rawurlencode((string)$p['avatar'])))
             . '" alt="" style="object-fit:cover">';
    }
    $tone = $p['role'] === 'admin' ? 'avatar-gold' : ($p['role'] === 'faculty' ? 'avatar-violet' : 'avatar-mint');
    return '<span class="avatar ' . $tone . '" aria-hidden="true">'
         . e(mb_strtoupper(mb_substr((string)$p['name'], 0, 2))) . '</span>';
}

$extraHead = '<link rel="stylesheet" href="' . e(url('assets/css/chat.css')) . '?v='
           . (@filemtime(ROOT_PATH . '/assets/css/chat.css') ?: time()) . '">';
$extraFoot = '<script src="' . e(url('assets/js/chat.js')) . '?v='
           . (@filemtime(ROOT_PATH . '/assets/js/chat.js') ?: time()) . '"></script>';

$pageLabel = 'Messages';
$pageSub   = 'Talk to your adviser, your students or the library.';
include ROOT_PATH . '/templates/layout/app_header.php';
?>

<?php if (!$contacts): ?>
<div class="card"><div class="empty">
  <i data-ico="mail" class="ico-xl"></i>
  <h4>Nobody to message yet</h4>
  <p class="mt-2" style="max-width:46ch;margin-inline:auto">
    <?= isStudent()
        ? 'Once the library assigns you an adviser, they appear here.'
        : 'Accounts you can message appear here as they are added.' ?>
  </p>
</div></div>
<?php else: ?>

<div class="chat"
     data-me="<?= (int)$uid ?>"
     data-with="<?= (int)$withId ?>"
     data-last="<?= (int)$lastId ?>"
     data-csrf="<?= e(csrfToken()) ?>"
     data-endpoint="<?= e(url('chat-api.php')) ?>">

  <aside class="chat-people" aria-label="Conversations">
    <div class="chat-search">
      <div class="input-icon">
        <i data-ico="search"></i>
        <label class="sr-only" for="people-filter">Filter conversations</label>
        <input class="input" id="people-filter" type="search" placeholder="Find someone" autocomplete="off">
      </div>
    </div>

    <div class="chat-list" role="list">
      <?php foreach ($contacts as $p): $isOn = (int)$p['id'] === $withId; ?>
      <a class="chat-person<?= $isOn ? ' is-active' : '' ?>"
         role="listitem"
         href="<?= url('messages.php?to=' . (int)$p['id']) ?>"
         data-person="<?= (int)$p['id'] ?>"
         data-name="<?= e(mb_strtolower((string)$p['name'])) ?>"
         <?= $isOn ? 'aria-current="page"' : '' ?>>
        <?= avatarFor($p) ?>
        <span class="grow" style="min-width:0">
          <span class="row-between" style="gap:8px">
            <span class="list-title truncate"><?= e($p['name']) ?></span>
            <span class="xs subtle" style="white-space:nowrap"><?= $p['last_at'] ? timeAgo($p['last_at']) : '' ?></span>
          </span>
          <span class="list-desc truncate">
            <?= $p['last_body']
                ? e(mb_strimwidth((string)$p['last_body'], 0, 44, '…'))
                : '<span class="subtle">' . e($p['role'] === 'admin' ? 'Library staff' : ucfirst((string)$p['role'])) . '</span>' ?>
          </span>
        </span>
        <span class="chat-badge badge badge-warn" data-unread="<?= (int)$p['id'] ?>"
              <?= (int)$p['unread'] ? '' : 'hidden' ?>><?= (int)$p['unread'] ?></span>
      </a>
      <?php endforeach; ?>
      <p class="chat-none small subtle center" hidden>Nobody by that name.</p>
    </div>
  </aside>

  <section class="chat-thread" aria-label="Conversation">
    <?php if (!$active): ?>
    <div class="empty" style="margin:auto">
      <i data-ico="mail" class="ico-xl"></i>
      <h4>Pick someone to talk to</h4>
      <p class="mt-2">Choose a name on the left to open the conversation.</p>
    </div>
    <?php else: ?>

    <header class="chat-head">
      <?= avatarFor($active) ?>
      <div class="grow" style="min-width:0">
        <p class="list-title truncate"><?= e($active['name']) ?></p>
        <p class="xs subtle truncate" data-presence>
          <?= e($active['role'] === 'admin' ? 'Library staff' : ucfirst((string)$active['role'])) ?><?php
            if ($active['department_name']) echo ' · ' . e($active['department_name']); ?>
        </p>
      </div>
      <?php if (isAdmin()): ?>
      <a class="btn btn-ghost btn-sm" href="<?= url('manage-users.php?q=' . rawurlencode((string)$active['name'])) ?>">
        <i data-ico="user" class="ico-sm"></i> Their account
      </a>
      <?php endif; ?>
    </header>

    <div class="chat-log" data-log tabindex="0" role="log" aria-live="polite"
         aria-label="Messages with <?= e($active['name']) ?>">
      <?php if (!$history): ?>
      <div class="chat-empty" data-empty>
        <i data-ico="mail" class="ico-lg"></i>
        <p class="mt-3 strong">No messages yet</p>
        <p class="small subtle mt-2">Say hello. Messages appear here for both of you.</p>
      </div>
      <?php else: $day = ''; foreach ($history as $m):
        $d = date('j F Y', strtotime((string)$m['created_at']));
        if ($d !== $day) { $day = $d; echo '<p class="chat-day"><span>' . e($d) . '</span></p>'; }
        $mine = (int)$m['sender_id'] === $uid; ?>
      <div class="chat-msg<?= $mine ? ' is-mine' : '' ?>" data-id="<?= (int)$m['id'] ?>">
        <div class="chat-bubble"><?= nl2br(e($m['body'])) ?></div>
        <p class="chat-time"><?= date('g:i a', strtotime((string)$m['created_at'])) ?><?php
          if ($mine) echo (int)$m['is_read'] ? ' · Read' : ' · Sent'; ?></p>
      </div>
      <?php endforeach; endif; ?>

      <div class="chat-msg chat-typing" data-typing hidden>
        <div class="chat-bubble">
          <span class="asst-dots"><span class="asst-dot"></span><span class="asst-dot"></span><span class="asst-dot"></span></span>
          <span class="sr-only"><?= e($active['name']) ?> is typing</span>
        </div>
      </div>
    </div>

    <p class="chat-error small" data-error hidden role="alert"></p>

    <form class="chat-form" data-form>
      <label class="sr-only" for="chat-body">Message <?= e($active['name']) ?></label>
      <textarea class="textarea chat-input" id="chat-body" data-input rows="1"
                placeholder="Write a message. Enter sends, Shift+Enter starts a line."
                maxlength="4000" autocomplete="off"></textarea>
      <button class="btn btn-primary btn-icon chat-send" type="submit" data-send aria-label="Send message">
        <i data-ico="arrowright"></i>
      </button>
    </form>
    <?php endif; ?>
  </section>
</div>
<?php endif; ?>

<?php include ROOT_PATH . '/templates/layout/app_footer.php'; ?>
