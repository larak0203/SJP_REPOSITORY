<?php
/* ============================================================================
   Shared helpers
   ============================================================================ */

declare(strict_types=1);

/* ------------------------------------------------------------ escaping ---- */
function e(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** Escape for use inside a JS string in an onclick attribute. */
function jsq(string $v): string {
    return htmlspecialchars(json_encode($v, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string {
    return BASE_URL . ltrim($path, '/');
}

function redirect(string $path): never {
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)));
    exit;
}

/* ------------------------------------------------------------ formatting -- */
function humanBytes(?int $bytes): string {
    $b = (int)$bytes;
    if ($b <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int)floor(log($b, 1024));
    $i = min($i, count($units) - 1);
    return round($b / (1024 ** $i), $i > 0 ? 1 : 0) . ' ' . $units[$i];
}

function humanDate(?string $ts, string $fmt = 'j F Y'): string {
    if (!$ts) return '—';
    $t = strtotime($ts);
    return $t ? date($fmt, $t) : '—';
}

function timeAgo(?string $ts): string {
    if (!$ts) return '';
    $diff = time() - (int)strtotime($ts);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800) return floor($diff / 86400) . ' d ago';
    return humanDate($ts, 'j M Y');
}

/** Split a semicolon-separated field into clean parts. */
function splitList(?string $v): array {
    return array_values(array_filter(array_map('trim', explode(';', (string)$v)), 'strlen'));
}

/* --------------------------------------------------------------- status --- */
function statusLabel(string $status): string {
    return WORKFLOW[$status] ?? ucfirst($status);
}

function statusBadge(string $status): string {
    $map = [
        'draft'        => ['badge',        'edit'],
        'submitted'    => ['badge-info',   'upload'],
        'under_review' => ['badge-warn',   'clock'],
        'revision'     => ['badge-violet', 'edit'],
        'approved'     => ['badge-ok',     'check'],
        'published'    => ['badge-ok',     'checkcircle'],
        'rejected'     => ['badge-danger', 'xcircle'],
        'archived'     => ['badge',        'archive'],
    ];
    [$cls, $ico] = $map[$status] ?? ['badge', 'info'];
    return '<span class="badge ' . $cls . '"><i data-ico="' . $ico . '"></i> ' . e(statusLabel($status)) . '</span>';
}

function accessBadge(string $level): string {
    return match ($level) {
        'open'       => '<span class="badge badge-ok"><i data-ico="globe"></i> Open access</span>',
        'campus'     => '<span class="badge badge-info"><i data-ico="pin"></i> Campus only</span>',
        'restricted' => '<span class="badge badge-warn"><i data-ico="lock"></i> Metadata only</span>',
        default      => '<span class="badge">' . e($level) . '</span>',
    };
}

/* ------------------------------------------------------------ identifiers - */
/** Mint the next SJP2CD-YYYY-NNNN identifier. Called at publication. */
function mintIdentifier(PDO $pdo): string {
    $year = date('Y');
    $stmt = $pdo->prepare(
        "SELECT dc_identifier FROM records
         WHERE dc_identifier LIKE ? ORDER BY dc_identifier DESC LIMIT 1"
    );
    $stmt->execute(["SJP2CD-{$year}-%"]);
    $last = $stmt->fetchColumn();
    $next = $last ? ((int)substr((string)$last, -4)) + 1 : 1;
    return sprintf('SJP2CD-%s-%04d', $year, $next);
}

/* ------------------------------------------------------------ activity ---- */
function logActivity(PDO $pdo, ?int $userId, string $action, ?string $details = null, ?int $recordId = null): void {
    $pdo->prepare(
        "INSERT INTO activity_log (user_id, record_id, action, details, ip_address)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$userId, $recordId, $action, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
}

/* -------------------------------------------------------- notifications --- */
function notify(PDO $pdo, int $userId, string $title, ?string $message = null,
                string $type = 'info', ?int $recordId = null): void {
    $pdo->prepare(
        "INSERT INTO notifications (user_id, record_id, type, title, message)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$userId, $recordId, $type, $title, $message]);
}

function unreadNotifications(PDO $pdo, int $userId): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $s->execute([$userId]);
    return (int)$s->fetchColumn();
}

function unreadMessages(PDO $pdo, int $userId): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0");
    $s->execute([$userId]);
    return (int)$s->fetchColumn();
}

/* ------------------------------------------------------------- flashes ---- */
function flash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function takeFlashes(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------------------------------------------------------------- CSRF ---- */
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfField(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
}

function csrfCheck(): bool {
    return isset($_POST['_csrf'], $_SESSION['csrf'])
        && hash_equals((string)$_SESSION['csrf'], (string)$_POST['_csrf']);
}

/** Abort a POST that fails the CSRF check. */
function requireCsrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrfCheck()) {
        http_response_code(400);
        exit('Invalid or expired form token. Go back and try again.');
    }
}
