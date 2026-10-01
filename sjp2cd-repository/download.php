<?php
/* Serve a stored file, honouring the record's access level.
   Also records a PREMIS "dissemination" event so downloads are auditable. */
require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/includes/metadata.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) redirect('browse.php');

$s = $pdo->prepare("SELECT * FROM records WHERE id = ?");
$s->execute([$id]);
$rec = $s->fetch();
if (!$rec) { http_response_code(404); flash('danger', 'That record does not exist.'); redirect('browse.php'); }

if (!isPublic($rec) && !isAdmin()
    && (int)$rec['submitted_by'] !== currentUserId()
    && (int)$rec['adviser_id']   !== currentUserId()) {
    http_response_code(403); flash('danger', 'That record is not published yet.'); redirect('browse.php');
}

if (!canReadFullText($rec)) {
    if (!isLoggedIn()) {
        /* Come back to the record, not to the dashboard — the reader was in the
           middle of something. */
        $_SESSION['redirect_after_login'] = url('record.php?id=' . $id);
        flash('info', 'Sign in with your college account to download the full text.');
        redirect('login.php');
    }
    flash('danger', 'The full text of this record is restricted. Ask the library if you need access.');
    redirect('record.php?id=' . $id);
}

$f = $pdo->prepare("SELECT * FROM record_files WHERE record_id = ? AND superseded_at IS NULL AND file_use IN ('ACCESS','ARCHIVE') ORDER BY FIELD(file_use,'ACCESS','ARCHIVE'), id DESC LIMIT 1");
$f->execute([$id]);
$file = $f->fetch();

if (!$file || !is_file($file['storage_path'])) {
    flash('danger', 'The file for this record is missing. The library has been notified.');
    redirect('record.php?id=' . $id);
}

$pdo->prepare("UPDATE records SET downloads = downloads + 1 WHERE id = ?")->execute([$id]);
$agent = isLoggedIn()
    ? premisAgentForUser($pdo, currentUserId(), (string)($_SESSION['name'] ?? 'user'))
    : premisAgentId($pdo, 'svc:repository', 'SJP2CD Repository Service', 'software');
premisEvent($pdo, $id, 'dissemination', 'success', 'Access copy delivered', $agent, (int)$file['id']);

header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . (string)filesize($file['storage_path']));
header('Content-Disposition: attachment; filename="' . basename((string)$file['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($file['storage_path']);
