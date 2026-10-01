<?php
/* ============================================================================
   Authentication and authorisation

   Three roles, three jobs:
     student  submits own work; it routes to their adviser
     faculty  reviews advisees' submissions; deposits own research
     admin    publishes, manages accounts, runs preservation
   ============================================================================ */

declare(strict_types=1);

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function currentUserId(): ?int {
    return isLoggedIn() ? (int)$_SESSION['user_id'] : null;
}

function currentRole(): string {
    return (string)($_SESSION['role'] ?? '');
}

function isAdmin(): bool   { return currentRole() === 'admin'; }
function isGuest(): bool   { return currentRole() === 'guest'; }

/** The role a college domain implies, or null if the address is not one.

    Matching is on the whole domain after the "@", so "sjp2cd.edu.ph" never
    matches "notsjp2cd.edu.ph" or "sjp2cd.edu.ph.example.com" -- an ending
    comparison alone would accept both. */
function institutionalRoleFor(string $email): ?string {
    $at = strrpos($email, '@');
    if ($at === false) return null;
    $domain = strtolower(trim(substr($email, $at + 1)));
    return INSTITUTIONAL_DOMAINS[$domain] ?? null;
}

/** Is this an address the college issues? */
function isInstitutionalEmail(string $email): bool {
    return institutionalRoleFor($email) !== null;
}

/** Every college domain, for messages that list them. */
function institutionalDomainList(): string {
    $d = array_map(static fn($x) => '@' . $x, array_keys(INSTITUTIONAL_DOMAINS));
    return count($d) === 1 ? $d[0] : implode(', ', array_slice($d, 0, -1)) . ' or ' . end($d);
}

/** The roles that belong to members of the college. */
function collegeRoles(): array { return ['student', 'faculty', 'admin']; }
function isFaculty(): bool { return currentRole() === 'faculty'; }
function isStudent(): bool { return currentRole() === 'student'; }

/** Who may put work into the repository.

    Students may not. The library's rule is that deposit is mediated: a member
    of faculty deposits their own research, and deposits the student work they
    supervised, naming the student as the author. This is ordinary practice --
    Alabama requires faculty sponsorship for undergraduate work, Washington
    requires faculty vetting, Tulane reviews every student submission -- and it
    is what Objective 3 asks for, which speaks of faculty self-archiving.

    A student account still matters: it is what opens a gated full text. */
function canDeposit(): bool {
    return isAdmin() || isFaculty();
}

/** Guard for the deposit form and anything that writes a new record. */
function requireDeposit(): void {
    requireLogin();
    if (!canDeposit()) {
        flash('info', 'Deposits are made by faculty and the library. Ask your adviser to deposit your work, naming you as the author.');
        redirect('dashboard.php');
    }
}

/** Who may act on the review queue. */
function canReview(): bool {
    return isAdmin() || isFaculty();
}

/** Only the library releases a record to the public collection. */
function canPublish(): bool {
    return isAdmin();
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? null;
        redirect('login.php');
    }
}

function requireRole(string ...$roles): void {
    requireLogin();
    if (!in_array(currentRole(), $roles, true)) {
        http_response_code(403);
        flash('danger', 'You do not have permission to open that page.');
        redirect('dashboard.php');
    }
}

/** Load the signed-in user's full row (department name included). */
function currentUser(PDO $pdo): ?array {
    static $cached = null;
    if ($cached !== null) return $cached;
    if (!isLoggedIn()) return null;

    $stmt = $pdo->prepare(
        "SELECT u.*, d.name AS department_name, d.code AS department_code,
                a.name AS adviser_name
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         LEFT JOIN users a       ON a.id = u.adviser_id
         WHERE u.id = ?"
    );
    $stmt->execute([currentUserId()]);
    $cached = $stmt->fetch() ?: null;
    return $cached;
}

function signIn(PDO $pdo, array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id']    = (int)$user['id'];
    $_SESSION['name']       = $user['name'];
    $_SESSION['email']      = $user['email'];
    $_SESSION['role']       = $user['role'];
    $_SESSION['avatar']     = $user['avatar'] ?? null;

    $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);
    logActivity($pdo, (int)$user['id'], 'Signed in', $user['email']);
}

function signOut(PDO $pdo): void {
    if (isLoggedIn()) {
        logActivity($pdo, currentUserId(), 'Signed out', (string)($_SESSION['email'] ?? ''));
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* --------------------------------------------------------- record access -- */

/**
 * Can the current visitor read this record's full text?
 *   open       anyone
 *   campus     any signed-in account
 *   restricted owner, adviser, or staff only
 * An embargo overrides everything until it lapses.
 */
function canReadFullText(array $record): bool {
    if (!empty($record['embargo_until']) && strtotime((string)$record['embargo_until']) > time()) {
        return isAdmin() || (int)($record['submitted_by'] ?? 0) === currentUserId();
    }
    /* A visitor from outside the college reads the catalogue and nothing more.
       Their account exists so they can search comfortably, not to open files. */
    if (isGuest()) return false;

    return match ($record['access_level'] ?? 'open') {
        /* Metadata for everyone; the file itself for college accounts. The
           abstract, keywords and every Dublin Core element stay public — only
           the full text asks the reader to sign in with a college account. */
        'open'       => isLoggedIn(),
        'campus'     => isLoggedIn(),
        'restricted' => isAdmin() || isFaculty()
                        || (int)($record['submitted_by'] ?? 0) === currentUserId()
                        || (int)($record['adviser_id'] ?? 0) === currentUserId(),
        default      => false,
    };
}

/** Can the current user edit or withdraw this record? */
function canEditRecord(array $record): bool {
    if (isAdmin()) return true;
    if ((int)($record['submitted_by'] ?? 0) !== currentUserId()) return false;
    return in_array($record['status'], ['draft', 'revision', 'rejected'], true);
}

/** Is this record visible in the public collection? */
function isPublic(array $record): bool {
    return in_array($record['status'], ['published', 'archived'], true);
}
