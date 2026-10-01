<?php
/**
 * =============================================================================
 * CREATE A LIBRARY (ADMIN) ACCOUNT
 * =============================================================================
 *
 * Library accounts are deliberately not available through public registration —
 * anyone reaching the sign-in page could otherwise grant themselves publishing
 * and deletion rights. They are created here, or promoted from People by an
 * existing admin.
 *
 * The password printed below is TEMPORARY and random. Change it on the Profile
 * page at first sign-in. It is shown once and is not stored anywhere in plain
 * text — only its hash goes into the database.
 *
 *   php sql/create-admin.php "Full Name" email@sjp2cd.edu.ph
 *
 * With no arguments it creates a general College Library account.
 */

require_once __DIR__ . '/../config/config.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    exit("For safety this script only runs from the command line:\n"
       . "  php sql/create-admin.php \"Full Name\" email@sjp2cd.edu.ph\n");
}

$name  = $argv[1] ?? 'College Library';
$email = strtolower(trim($argv[2] ?? 'library@sjp2cd.edu.ph'));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("That is not a valid email address: {$email}\n");
}

$exists = $pdo->prepare("SELECT id, name, role FROM users WHERE email = ?");
$exists->execute([$email]);

if ($row = $exists->fetch()) {
    if ($row['role'] === 'admin') {
        exit("{$email} already exists as a library account (id {$row['id']}, {$row['name']}).\n"
           . "Nothing changed. Use the Profile page to reset its password.\n");
    }
    $pdo->prepare("UPDATE users SET role = 'admin', adviser_id = NULL WHERE id = ?")->execute([$row['id']]);
    echo "Promoted {$row['name']} ({$email}) from {$row['role']} to library staff.\n";
    echo "Their existing password is unchanged.\n";
    exit;
}

/* A random temporary password rather than a guessable default. */
$temp = 'Lib-' . bin2hex(random_bytes(4)) . '-' . random_int(100, 999);

$pdo->prepare(
    "INSERT INTO users (name, email, password_hash, role, is_active)
     VALUES (?, ?, ?, 'admin', 1)"
)->execute([$name, $email, password_hash($temp, PASSWORD_DEFAULT)]);

$id = (int)$pdo->lastInsertId();
logActivity($pdo, null, 'created a library account', $email);

echo str_repeat('-', 62) . "\n";
echo "Library account created.\n";
echo str_repeat('-', 62) . "\n";
echo "  id        {$id}\n";
echo "  name      {$name}\n";
echo "  email     {$email}\n";
echo "  role      admin (library staff)\n";
echo "  password  {$temp}\n";
echo str_repeat('-', 62) . "\n";
echo "This password is temporary and shown only once. Sign in, then change it\n";
echo "on the Profile page. Only its hash is stored in the database.\n";
