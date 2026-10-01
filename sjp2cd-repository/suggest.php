<?php
/* ============================================================================
   Search suggestions.

   Returns what the collection actually contains that starts with, or contains,
   what has been typed -- titles, authors and keywords. It never invents a
   suggestion: every line offered here is backed by at least one published
   record, so a reader who picks one always lands on results.

   Only published and archived records are considered. A draft title must not
   leak through an autocomplete, which is an easy way for a system like this to
   disclose work nobody has agreed to publish yet.
   ============================================================================ */

require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/includes/metadata.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=30');

$q = trim((string)($_GET['q'] ?? ''));

/* Two characters is the point at which suggestions stop being noise. */
if (mb_strlen($q) < 2) {
    echo json_encode(['q' => $q, 'groups' => []]);
    exit;
}

$like    = '%' . $q . '%';
$visible = "status IN ('published','archived')";
$groups  = [];

/* ---- titles ---- */
$st = $pdo->prepare(
    "SELECT id, dc_title FROM records
     WHERE $visible AND dc_title LIKE ?
     ORDER BY CASE WHEN dc_title LIKE ? THEN 0 ELSE 1 END, downloads DESC
     LIMIT 5"
);
$st->execute([$like, $q . '%']);
$titles = [];
foreach ($st->fetchAll() as $r) {
    $titles[] = ['label' => (string)$r['dc_title'], 'href' => url('record.php?id=' . (int)$r['id'])];
}
if ($titles) $groups[] = ['label' => 'Works', 'icon' => 'book', 'items' => $titles];

/* ---- authors and keywords -------------------------------------------------
   Both are stored as semicolon-separated lists, so they are split in PHP and
   counted rather than matched whole. The lists are small enough that this is
   cheaper than a second set of tables to maintain. */
$rows = $pdo->query("SELECT dc_creator, dc_subject FROM records WHERE $visible")->fetchAll();

$collect = static function (array $rows, string $col, string $needle): array {
    $seen = [];
    foreach ($rows as $row) {
        foreach (splitList((string)$row[$col]) as $v) {
            $v = trim($v);
            if ($v === '' || mb_stripos($v, $needle) === false) continue;
            $key = mb_strtolower($v);
            if (!isset($seen[$key])) $seen[$key] = ['label' => $v, 'n' => 0];
            $seen[$key]['n']++;
        }
    }
    /* Names that appear on more work are the more useful guess. */
    uasort($seen, static fn($a, $b) => $b['n'] <=> $a['n'] ?: strcmp($a['label'], $b['label']));
    return array_slice(array_values($seen), 0, 4);
};

foreach ([['dc_creator', 'Authors', 'users', 'author'],
          ['dc_subject', 'Keywords', 'tag', 'subject']] as [$col, $label, $icon, $param]) {
    $items = [];
    foreach ($collect($rows, $col, $q) as $hit) {
        $items[] = [
            'label' => $hit['label'],
            'note'  => $hit['n'] . ' ' . ($hit['n'] === 1 ? 'work' : 'works'),
            'href'  => url('browse.php?' . http_build_query([$param => $hit['label']])),
        ];
    }
    if ($items) $groups[] = ['label' => $label, 'icon' => $icon, 'items' => $items];
}

echo json_encode(['q' => $q, 'groups' => $groups], JSON_UNESCAPED_UNICODE);
