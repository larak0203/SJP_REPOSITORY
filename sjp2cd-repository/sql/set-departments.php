<?php
/**
 * =============================================================================
 * SET THE DEPARTMENT LIST
 * =============================================================================
 *
 * Replaces the placeholder departments with the college's real programmes.
 *
 * The nine departments this system started with were invented during
 * development and were never real. This script puts that right.
 *
 * It updates rows in place rather than deleting and re-inserting, so existing
 * records and user accounts keep pointing at a valid department throughout. A
 * department that is no longer needed is removed only if nothing references it;
 * if something does, the script says so and leaves it alone rather than
 * orphaning data.
 *
 *   CLI      php sql/set-departments.php
 *   Browser  /sjp2cd-repository/sql/set-departments.php?confirm=yes
 */

require_once __DIR__ . '/../config/config.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    if (($_GET['confirm'] ?? '') !== 'yes') {
        exit("Add ?confirm=yes to rewrite the department list.\n");
    }
}
$out = static fn(string $l) => print($l . "\n");

/* Ordered deliberately: each slot reuses the id a comparable department already
   held, so accounts and records stay with a sensible programme. */
$DEPARTMENTS = [
    1 => ['BSIT',  'BS Information Technology'],
    2 => ['BEEd',  'Teacher Education'],
    3 => ['BSBA',  'BS Business Administration'],
    4 => ['BSN',   'BS Nursing'],
    5 => ['BSHM',  'BS Hospitality Management'],
    6 => ['BSCpE', 'BS Computer Engineering'],
    7 => ['BSGE',  'BS Geodetic Engineering'],
    8 => ['BSHRM', 'BS Hotel and Restaurant Management'],
];

$out('Setting the department list…');
$out('');

$existing = [];
foreach ($pdo->query("SELECT id, code, name FROM departments")->fetchAll() as $d) {
    $existing[(int)$d['id']] = $d;
}

$upd = $pdo->prepare("UPDATE departments SET code = ?, name = ? WHERE id = ?");
$ins = $pdo->prepare("INSERT INTO departments (id, code, name) VALUES (?, ?, ?)");

foreach ($DEPARTMENTS as $id => [$code, $name]) {
    if (isset($existing[$id])) {
        $was = $existing[$id];
        $upd->execute([$code, $name, $id]);
        $out(sprintf('  %d  %-6s %-38s %s', $id, $code, $name,
            ($was['code'] === $code) ? '(unchanged)' : 'was ' . $was['code']));
    } else {
        $ins->execute([$id, $code, $name]);
        $out(sprintf('  %d  %-6s %-38s added', $id, $code, $name));
    }
}

/* Anything beyond the new list is retired, but only when nothing points at it. */
$out('');
foreach ($existing as $id => $d) {
    if (isset($DEPARTMENTS[$id])) continue;

    $r = $pdo->prepare("SELECT COUNT(*) FROM records WHERE department_id = ?");
    $r->execute([$id]);
    $u = $pdo->prepare("SELECT COUNT(*) FROM users WHERE department_id = ?");
    $u->execute([$id]);
    $records = (int)$r->fetchColumn();
    $users   = (int)$u->fetchColumn();

    if ($records || $users) {
        $out(sprintf('  KEPT %d (%s) — still used by %d record(s) and %d account(s).',
            $id, $d['code'], $records, $users));
        $out('       Move them to another department first, then re-run this script.');
    } else {
        $pdo->prepare("DELETE FROM departments WHERE id = ?")->execute([$id]);
        $out(sprintf('  removed %d (%s) — nothing referenced it', $id, $d['code']));
    }
}

$out('');
$out('Department list is now:');
foreach ($pdo->query("SELECT d.id, d.code, d.name,
                             (SELECT COUNT(*) FROM records r WHERE r.department_id = d.id) records,
                             (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id) people
                      FROM departments d ORDER BY d.id")->fetchAll() as $d) {
    $out(sprintf('  %d  %-6s %-38s %2d records, %d people',
        $d['id'], $d['code'], $d['name'], $d['records'], $d['people']));
}
$out('');
$out('Run sql/seed-sample-data.php next to regenerate the sample records against these.');
