<?php
/**
 * Google Sign-In, restricted to college accounts.
 *
 * Two jobs in one file:
 *   no ?code   → send the reader to Google
 *   ?code=...  → exchange it, verify the account, sign them in
 *
 * It refuses to do anything unless GOOGLE_ENABLED is true, so an unconfigured
 * install cannot reach a half-working flow.
 *
 * Honest limitations, both by design:
 *   · it needs the internet, so it cannot work at an offline defence
 *   · email and password stays the primary path and is never disabled
 *
 * Only @sjp2cd.edu.ph accounts are accepted. Google's `hd` claim is checked on
 * the server rather than trusting the `hd` parameter sent in the request, which
 * a caller can change freely.
 */
require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/includes/metadata.php';   /* notifyLibrary() */

/** Record what happened, then send the reader back with the reason on screen. */
function googleStop(string $reason, string $shown): never {
    $dir = ROOT_PATH . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($dir . '/auth-google.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $reason . PHP_EOL, FILE_APPEND);
    flash('danger', $shown);
    redirect('login.php');
}

if (!GOOGLE_ENABLED) {
    flash('warn', 'Google Sign-In is not configured on this installation. Use your email and password.');
    redirect('login.php');
}
if (isLoggedIn()) redirect('dashboard.php');

$redirectUri = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
             . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('auth-google.php');

/* ------------------------------------------------- step 1: go to Google --- */
if (!isset($_GET['code'])) {
    $state = bin2hex(random_bytes(16));
    $_SESSION['google_state'] = $state;

    $params = [
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'hd'            => GOOGLE_HOSTED_DOMAIN,   /* a hint only — verified below */
        'prompt'        => 'select_account',
    ];
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params));
    exit;
}

/* ------------------------------------------------ step 2: come back here --- */

if (!isset($_SESSION['google_state'])
    || !hash_equals((string)$_SESSION['google_state'], (string)($_GET['state'] ?? ''))) {
    unset($_SESSION['google_state']);
    googleStop('state mismatch (session lost between leaving and returning)',
        'That sign-in attempt could not be verified. Please try again.');
}
unset($_SESSION['google_state']);

/** Small POST helper — no Composer here, so cURL directly. */
function googlePost(string $url, array $fields): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code < 200 || $code > 299) {
        $GLOBALS['google_last_error'] = $body === false
            ? 'cURL: ' . $err
            : 'HTTP ' . $code . ' ' . substr((string)$body, 0, 400);
        return null;
    }
    $json = json_decode((string)$body, true);
    return is_array($json) ? $json : null;
}

$token = googlePost('https://oauth2.googleapis.com/token', [
    'code'          => (string)$_GET['code'],
    'client_id'     => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code',
]);

if (!$token || empty($token['id_token'])) {
    googleStop('token exchange failed -- ' . ($GLOBALS['google_last_error'] ?? 'no detail'),
        'Google did not complete the sign-in. Use your email and password instead.');
}

/* Verify the id_token with Google rather than decoding it here — this install
   has no JWT library, and an unverified token is worth nothing. */
$ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode((string)$token['id_token']));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_SSL_VERIFYPEER => true]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);
$claims = ($raw !== false && $code === 200) ? json_decode((string)$raw, true) : null;

if (!is_array($claims) || ($claims['aud'] ?? '') !== GOOGLE_CLIENT_ID) {
    googleStop('id_token did not verify, or aud is not this client: ' . json_encode($claims),
        'That Google account could not be verified.');
}
if (($claims['email_verified'] ?? '') !== 'true' && ($claims['email_verified'] ?? false) !== true) {
    googleStop('email_verified was not true',
        'That Google account has no verified email address.');
}

$email  = strtolower(trim((string)($claims['email'] ?? '')));
$domain = (string)($claims['hd'] ?? '');
$name   = trim((string)($claims['name'] ?? '')) ?: strstr($email, '@', true);

/* The domain check, on the server, against the verified claim -- and against
   the same list the rest of the system uses, so Google cannot let in an address
   that email-and-password would refuse.

   Google's hd claim is checked where it is present; where it is not (a personal
   account), the verified address itself has to be on a college domain. */
$domainRole = institutionalRoleFor($email);
if ($domainRole === null || ($domain !== '' && institutionalRoleFor('x@' . $domain) === null)) {
    googleStop('address not on a college domain: ' . $email . ' (hd=' . $domain . ')',
        'Only college accounts can sign in with Google. Yours is ' . e($email) . '.');
}

/* ---------------------------------------------------------- sign them in --- */
$stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
$stmt->execute([$email]);
$user = $stmt->fetch();

if ($user && !(int)$user['is_active']) {
    if (!empty($user['deactivated_at'])) {
        /* Closed on purpose by the library. Google proves who they are, not
           that they are still welcome. */
        googleStop('account closed by the library: ' . $email,
            'That account has been closed. Ask the library to restore it.');
    }

    /* Never confirmed, and now signed in through the college's own Google
       account. That is exactly what the librarian was being asked to vouch
       for, proved directly, so the account opens itself. */
    $pdo->prepare("UPDATE users SET is_active = 1 WHERE id = ?")->execute([(int)$user['id']]);
    $user['is_active'] = 1;
    logActivity($pdo, (int)$user['id'], 'account confirmed by Google sign-in', $email);
    notifyLibrary($pdo, 'An account confirmed itself with Google',
        $user['name'] . ' (' . $email . ') signed in with their college Google account, so the account was opened automatically.',
        'account', null);
    flash('ok', 'Your college Google account confirmed this account. Welcome.');
}

if (!$user) {
    /* First sign-in creates a student account with no usable password — the
       account can only be entered through Google until the library changes it. */
    $pdo->prepare(
        "INSERT INTO users (name, email, password_hash, role, is_active)
         VALUES (?, ?, ?, ?, 1)"
    )->execute([$name, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $domainRole]);

    $stmt->execute([$email]);
    $user = $stmt->fetch();

    logActivity($pdo, (int)$user['id'], 'created an account with Google', $email);
    notifyLibrary($pdo, 'A new account was created',
        $name . ' (' . $email . ') signed in with a college Google account.', 'account', null);
    flash('ok', 'Welcome. Your account was created from your college Google address.');
}

signIn($pdo, $user);
$to = $_SESSION['redirect_after_login'] ?? null;
unset($_SESSION['redirect_after_login']);
redirect($to ?: 'dashboard.php');
