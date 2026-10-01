<?php
/* ============================================================================
   Scheduled maintenance -- run by Windows Task Scheduler, not by a browser.

   Does the three jobs that should never depend on someone remembering them:

     1. Archive sweep   moves published work older than ARCHIVE_AFTER_YEARS
                        into the archive (the Scope and Limitation rule)
     2. Fixity audit    re-hashes every stored file against its deposit digest
     3. Backup          copies the database and every file to BACKUP_PATH and
                        verifies each copy

   Usage:
       C:\xampp\php\php.exe C:\xampp\htdocs\sjp2cd-repository\sql\maintenance.php
       ... maintenance.php --skip-backup      audit and archive only

   MySQL must be running. The result of every run is appended to
   logs/maintenance.log, and each job is also recorded in the database the same
   way a librarian's action would be.
   ============================================================================ */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Run this from the command line.\n"); }

require __DIR__ . '/../config/config.php';
require_once ROOT_PATH . '/includes/maintenance.php';

$skipBackup = in_array('--skip-backup', $argv, true);

$logDir = ROOT_PATH . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0775, true);
$log = static function (string $line) use ($logDir): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $line;
    echo $line, PHP_EOL;
    file_put_contents($logDir . '/maintenance.log', $line . PHP_EOL, FILE_APPEND);
};

/* The work is done by the repository itself, so it is recorded as the
   repository's own software agent rather than as a person. */
$service = premisAgentId($pdo, 'svc:maintenance', 'SJP2CD Scheduled Maintenance', 'software');
$status  = 0;

$log('--- maintenance started ---');

/* 1. archive sweep ------------------------------------------------------- */
try {
    $moved = archiveExpiredRecords($pdo, null);
    $log('archive sweep: ' . $moved . ' record(s) moved to the archive');
} catch (Throwable $e) {
    $log('archive sweep FAILED: ' . $e->getMessage()); $status = 1;
}

/* 2. fixity audit -------------------------------------------------------- */
try {
    $a = runFixityAudit($pdo, null, $service);
    $log(sprintf('fixity audit #%d: %d checked, %d passed, %d failed, %d unverifiable (%d ms)',
        $a['audit_id'], $a['checked'], $a['passed'], $a['failed'], $a['unverifiable'], $a['ms']));
    if ($a['failed'] > 0 || $a['unverifiable'] > 0) {
        notifyLibrary($pdo, 'The scheduled integrity check found a problem',
            $a['failed'] . ' file(s) failed and ' . $a['unverifiable'] . ' could not be checked. Open Preservation for details.',
            'fixity');
        $status = 1;
    }
} catch (Throwable $e) {
    $log('fixity audit FAILED: ' . $e->getMessage()); $status = 1;
}

/* 3. backup -------------------------------------------------------------- */
if ($skipBackup) {
    $log('backup: skipped (--skip-backup)');
} else {
    try {
        $b = runBackup($pdo, BACKUP_PATH);
        $log('backup: ' . $b['message'] . ' -> ' . $b['folder'] . ' (' . humanBytes($b['bytes']) . ')');

        if ($b['ok']) {
            /* A verified second copy is a PREMIS "replication" event: it is the
               evidence that the repository holds more than one copy. */
            $ids = $pdo->query("SELECT DISTINCT record_id FROM record_files")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $rid) {
                premisEvent($pdo, (int)$rid, 'replication', 'success',
                    'Copied to ' . basename($b['folder']) . '; ' . DIGEST_LABEL . ' of the copy verified', $service);
            }
        } else {
            notifyLibrary($pdo, 'The scheduled backup did not complete', $b['message'], 'backup');
            $status = 1;
        }
    } catch (Throwable $e) {
        $log('backup FAILED: ' . $e->getMessage()); $status = 1;
    }
}

$log('--- maintenance finished' . ($status ? ' WITH PROBLEMS' : '') . ' ---');
exit($status);
