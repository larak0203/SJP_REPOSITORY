<?php
/* METS export.  /mets.php?id=N  or  ?id=N&download=1 */
require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/includes/metadata.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Missing record id.'); }

$s = $pdo->prepare("SELECT id, dc_title, status, submitted_by, adviser_id FROM records WHERE id = ?");
$s->execute([$id]);
$rec = $s->fetch();
if (!$rec) { http_response_code(404); exit('No such record.'); }

$allowed = isPublic($rec) || isAdmin()
        || (int)$rec['submitted_by'] === currentUserId()
        || (int)$rec['adviser_id']   === currentUserId();
if (!$allowed) { http_response_code(403); exit('This package is not public.'); }

$xml = buildMetsXml($pdo, $id);
if ($xml === null) { http_response_code(500); exit('Could not build the package.'); }

header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');
if (!empty($_GET['download'])) {
    $slug = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$rec['dc_title'])), '-');
    header('Content-Disposition: attachment; filename="mets-' . $id . '-' . substr($slug, 0, 60) . '.xml"');
}
echo $xml;
