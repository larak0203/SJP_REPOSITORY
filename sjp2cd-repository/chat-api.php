<?php
/**
 * JSON endpoint behind the live chat.
 *
 * Every action is scoped to the signed-in user: you can only read a thread you
 * are part of, and you can only ever write as yourself. The client polls
 * `poll` while the tab is visible.
 */
require_once __DIR__ . '/config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $payload, int $code = 200): never {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isLoggedIn()) out(['ok' => false, 'error' => 'Your session has ended. Sign in again.'], 401);

$uid    = currentUserId();
$action = (string)($_REQUEST['action'] ?? '');

/* Writes need the token; reads do not, so polling survives a long-lived tab. */
if (in_array($action, ['send', 'typing'], true) && !csrfCheck()) {
    out(['ok' => false, 'error' => 'Your form token expired. Reload the page.'], 400);
}

/** Confirm the other party exists and is not the user themselves. */
function partner(PDO $pdo, int $uid, int $withId): array {
    if ($withId <= 0 || $withId === $uid) {
        out(['ok' => false, 'error' => 'Pick someone else to talk to.'], 400);
    }
    $s = $pdo->prepare("SELECT id, name, role, avatar, last_login_at FROM users WHERE id = ? AND is_active = 1");
    $s->execute([$withId]);
    $p = $s->fetch();
    if (!$p) out(['ok' => false, 'error' => 'That person is not available.'], 404);
    return $p;
}

switch ($action) {

    /* ---------------------------------------------------------- polling --- */
    case 'poll': {
        $withId = (int)($_GET['with'] ?? 0);
        $since  = (int)($_GET['since'] ?? 0);

        $messages = [];
        $typing   = false;

        if ($withId > 0) {
            partner($pdo, $uid, $withId);

            $m = $pdo->prepare(
                "SELECT id, sender_id, receiver_id, body, is_read, created_at
                 FROM messages
                 WHERE ((sender_id = :me AND receiver_id = :them)
                     OR (sender_id = :them2 AND receiver_id = :me2))
                   AND id > :since
                 ORDER BY id ASC LIMIT 200"
            );
            $m->execute([':me' => $uid, ':them' => $withId, ':them2' => $withId, ':me2' => $uid, ':since' => $since]);

            foreach ($m->fetchAll() as $row) {
                $messages[] = [
                    'id'    => (int)$row['id'],
                    'mine'  => (int)$row['sender_id'] === $uid,
                    'body'  => (string)$row['body'],
                    'read'  => (bool)(int)$row['is_read'],
                    'at'    => (string)$row['created_at'],
                    'time'  => date('g:i a', strtotime((string)$row['created_at'])),
                    'day'   => date('j F Y', strtotime((string)$row['created_at'])),
                ];
            }

            /* Anything they sent me is now on screen, so mark it read. */
            $pdo->prepare(
                "UPDATE messages SET is_read = 1, read_at = NOW()
                 WHERE receiver_id = ? AND sender_id = ? AND is_read = 0"
            )->execute([$uid, $withId]);

            $t = $pdo->prepare(
                "SELECT 1 FROM typing_status
                 WHERE user_id = ? AND typing_with = ? AND last_typed_at > (NOW() - INTERVAL 6 SECOND)"
            );
            $t->execute([$withId, $uid]);
            $typing = (bool)$t->fetchColumn();
        }

        /* Per-thread unread counts, so the contact list badges stay live. */
        $u = $pdo->prepare(
            "SELECT sender_id, COUNT(*) n FROM messages
             WHERE receiver_id = ? AND is_read = 0 GROUP BY sender_id"
        );
        $u->execute([$uid]);
        $unread = [];
        foreach ($u->fetchAll() as $row) { $unread[(string)(int)$row['sender_id']] = (int)$row['n']; }

        out([
            'ok'       => true,
            'messages' => $messages,
            'typing'   => $typing,
            'unread'   => $unread,
            'total'    => array_sum($unread),
            'notes'    => unreadNotifications($pdo, $uid),
        ]);
    }

    /* ----------------------------------------------------------- sending --- */
    case 'send': {
        $withId = (int)($_POST['with'] ?? 0);
        $body   = trim((string)($_POST['body'] ?? ''));

        $them = partner($pdo, $uid, $withId);

        if ($body === '')            out(['ok' => false, 'error' => 'Write something first.'], 400);
        if (mb_strlen($body) > 4000) out(['ok' => false, 'error' => 'That message is too long. Keep it under 4000 characters.'], 400);

        $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, body) VALUES (?, ?, ?)")
            ->execute([$uid, $withId, $body]);
        $id = (int)$pdo->lastInsertId();

        /* Stop showing me as typing the moment the message lands. */
        $pdo->prepare("DELETE FROM typing_status WHERE user_id = ? AND typing_with = ?")->execute([$uid, $withId]);

        /* One notification per conversation, not one per message. */
        $recent = $pdo->prepare(
            "SELECT COUNT(*) FROM notifications
             WHERE user_id = ? AND type = 'message' AND is_read = 0"
        );
        $recent->execute([$withId]);
        if (!(int)$recent->fetchColumn()) {
            notify($pdo, $withId, 'New message from ' . (string)currentUser($pdo)['name'],
                   mb_strimwidth($body, 0, 120, '…'), 'message');
        }

        out([
            'ok' => true,
            'message' => [
                'id'   => $id,
                'mine' => true,
                'body' => $body,
                'read' => false,
                'at'   => date('Y-m-d H:i:s'),
                'time' => date('g:i a'),
                'day'  => date('j F Y'),
            ],
        ]);
    }

    /* ---------------------------------------------------- typing signal --- */
    case 'typing': {
        $withId = (int)($_POST['with'] ?? 0);
        partner($pdo, $uid, $withId);

        if ((int)($_POST['stop'] ?? 0) === 1) {
            $pdo->prepare("DELETE FROM typing_status WHERE user_id = ? AND typing_with = ?")->execute([$uid, $withId]);
        } else {
            $pdo->prepare(
                "INSERT INTO typing_status (user_id, typing_with, last_typed_at) VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE last_typed_at = NOW()"
            )->execute([$uid, $withId]);
        }
        out(['ok' => true]);
    }

    default:
        out(['ok' => false, 'error' => 'Unknown action.'], 400);
}
