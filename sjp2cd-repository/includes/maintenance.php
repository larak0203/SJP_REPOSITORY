<?php
/* ============================================================================
   Maintenance: integrity audits and backup copies.

   Two jobs that keep the collection trustworthy without anyone having to
   remember them. Both are called from the Preservation page (on request) and
   from sql/maintenance.php (on a schedule), so the work done is identical
   whichever way it starts.

   OAIS calls this Archival Storage: keeping what was deposited unchanged, and
   keeping more than one copy of it.
   ============================================================================ */

require_once ROOT_PATH . '/includes/metadata.php';

/**
 * Recompute the digest of every stored file and compare it with the one taken
 * at deposit. Writes one fixity_results row per file and one PREMIS
 * "fixity check" event per file, inside a single transaction: an audit that
 * cannot finish records nothing, rather than half a result.
 *
 * Superseded files are checked too. A previous version is kept on purpose, so
 * it has to stay intact like everything else.
 *
 * @return array{audit_id:int, checked:int, passed:int, failed:int, unverifiable:int, ms:int}
 */
function runFixityAudit(PDO $pdo, ?int $userId, int $agentId): array {
    $started = microtime(true);
    $files   = $pdo->query("SELECT * FROM record_files ORDER BY id")->fetchAll();
    $passed  = $failed = $unverifiable = 0;

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO fixity_audits (run_at, run_by, objects_checked, passed, failed, unverifiable, duration_ms)
             VALUES (NOW(), ?, 0, 0, 0, 0, 0)"
        )->execute([$userId]);
        $auditId = (int)$pdo->lastInsertId();

        $insert = $pdo->prepare(
            "INSERT INTO fixity_results (audit_id, file_id, record_id, expected_digest, actual_digest, result)
             VALUES (?, ?, ?, ?, ?, ?)"
        );

        foreach ($files as $f) {
            $check = verifyFixity($f);
            $insert->execute([
                $auditId, (int)$f['id'], (int)$f['record_id'],
                $check['expected'], $check['actual'], $check['result'],
            ]);

            match ($check['result']) {
                'passed' => $passed++,
                'failed' => $failed++,
                default  => $unverifiable++,
            };

            /* A fixity check is itself a preservation event -- PREMIS says so. */
            premisEvent(
                $pdo, (int)$f['record_id'], 'fixity check',
                $check['result'] === 'passed' ? 'success' : 'failure',
                $check['result'] === 'passed'
                    ? DIGEST_LABEL . ' recomputed and matched the stored digest'
                    : DIGEST_LABEL . ' check returned ' . $check['result'],
                $agentId, (int)$f['id']
            );
        }

        $ms = (int)round((microtime(true) - $started) * 1000);
        $pdo->prepare(
            "UPDATE fixity_audits SET objects_checked = ?, passed = ?, failed = ?, unverifiable = ?, duration_ms = ?
             WHERE id = ?"
        )->execute([count($files), $passed, $failed, $unverifiable, $ms, $auditId]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    return ['audit_id' => $auditId, 'checked' => count($files), 'passed' => $passed,
            'failed' => $failed, 'unverifiable' => $unverifiable, 'ms' => $ms];
}

/**
 * Make a complete, verified copy of the repository somewhere else.
 *
 * A backup is only worth something if it is known to be good, so every copied
 * manuscript is hashed again at the destination and compared with the digest
 * taken at deposit. The result is written to manifest.csv beside the copy, and
 * the backup counts as successful only if every file matched.
 *
 * What goes in each dated folder:
 *   database.sql   the whole database, from mysqldump
 *   uploads/       every deposited file and profile photo
 *   manifest.csv   each manuscript, its digest, and whether the copy matched
 *
 * @return array{ok:bool, folder:string, files:int, verified:int, mismatched:int, bytes:int, message:string}
 */
function runBackup(PDO $pdo, string $destRoot): array {
    $stamp  = date('Y-m-d_His');
    $folder = rtrim(str_replace('\\', '/', $destRoot), '/') . '/sjp2cd-backup_' . $stamp;
    $fail   = static fn(string $m) => ['ok' => false, 'folder' => $folder, 'files' => 0,
                                       'verified' => 0, 'mismatched' => 0, 'bytes' => 0, 'message' => $m];

    if (!is_dir($folder) && !@mkdir($folder, 0775, true)) {
        return $fail('Could not create ' . $folder . '. Check the drive is connected and BACKUP_PATH is right.');
    }

    /* ---- 1. the database ---- */
    $dump = MYSQLDUMP_BIN;
    if (!is_file($dump)) return $fail('mysqldump was not found at ' . $dump . '.');

    $db   = $GLOBALS['DB_NAME'] ?? 'sjp2cd_repository';
    $user = $GLOBALS['DB_USER'] ?? 'root';
    $pass = $GLOBALS['DB_PASS'] ?? '';
    $host = $GLOBALS['DB_HOST'] ?? 'localhost';

    $cmd = escapeshellarg($dump)
         . ' --host=' . escapeshellarg($host)
         . ' --user=' . escapeshellarg($user)
         . ($pass !== '' ? ' --password=' . escapeshellarg($pass) : '')
         . ' --single-transaction --routines --default-character-set=utf8mb4 '
         . escapeshellarg($db)
         . ' --result-file=' . escapeshellarg($folder . '/database.sql')
         . ' 2>&1';
    exec($cmd, $out, $code);
    if ($code !== 0 || !is_file($folder . '/database.sql') || filesize($folder . '/database.sql') < 1024) {
        return $fail('The database dump failed: ' . trim(implode(' ', $out)));
    }

    /* ---- 2. the files ---- */
    $src = ROOT_PATH . '/uploads';
    $bytes = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $rel    = substr(str_replace('\\', '/', $item->getPathname()), strlen(str_replace('\\', '/', $src)) + 1);
        $target = $folder . '/uploads/' . $rel;
        if ($item->isDir()) {
            if (!is_dir($target)) mkdir($target, 0775, true);
        } else {
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0775, true);
            if (!copy($item->getPathname(), $target)) return $fail('Could not copy ' . $rel . '.');
            $bytes += (int)filesize($target);
        }
    }

    /* ---- 3. prove the copies are good ---- */
    $manifest = fopen($folder . '/manifest.csv', 'w');
    fputcsv($manifest, ['record_id', 'file_id', 'stored_name', 'expected_sha256', 'copy_sha256', 'result']);
    $files = $pdo->query("SELECT id, record_id, stored_name, checksum FROM record_files ORDER BY id")->fetchAll();
    $verified = $mismatched = 0;
    foreach ($files as $f) {
        $copy   = $folder . '/uploads/records/' . $f['stored_name'];
        $actual = is_file($copy) ? hash_file(DIGEST_ALGO, $copy) : '';
        $ok     = $actual !== '' && hash_equals((string)$f['checksum'], $actual);
        $ok ? $verified++ : $mismatched++;
        fputcsv($manifest, [$f['record_id'], $f['id'], $f['stored_name'], $f['checksum'], $actual,
                            $ok ? 'match' : ($actual === '' ? 'missing' : 'MISMATCH')]);
    }
    fclose($manifest);

    return [
        'ok'         => $mismatched === 0,
        'folder'     => $folder,
        'files'      => count($files),
        'verified'   => $verified,
        'mismatched' => $mismatched,
        'bytes'      => $bytes,
        'message'    => $mismatched === 0
            ? 'Backup complete. All ' . count($files) . ' manuscripts copied and verified.'
            : $mismatched . ' manuscript copies did not match their digest. See manifest.csv.',
    ];
}
