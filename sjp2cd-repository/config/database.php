<?php
/* ============================================================================
   Database connection (PDO)
   ============================================================================ */

declare(strict_types=1);

$DB_HOST = 'localhost';
$DB_NAME = 'sjp2cd_repository';
$DB_USER = 'root';
$DB_PASS = '';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(503);
    $isSetup = str_contains($e->getMessage(), 'Unknown database');
    ?>
    <!DOCTYPE html>
    <html lang="en"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Database unavailable</title>
    <style>
      body{font-family:system-ui,Segoe UI,sans-serif;background:#F2F2FD;color:#04051C;
           display:grid;place-items:center;min-height:100vh;margin:0;padding:24px}
      .box{background:#fff;border:1px solid #C4C6F6;border-radius:16px;padding:32px;
           max-width:560px;box-shadow:0 12px 32px rgba(15,17,117,.10)}
      h1{font-size:20px;margin:0 0 12px;color:#0F1175}
      p{line-height:1.7;color:#475569;margin:0 0 12px}
      code{background:#F2F2FD;padding:2px 6px;border-radius:4px;font-size:13px}
      a{color:#1A1D92}
    </style></head><body>
      <div class="box">
        <h1><?= $isSetup ? 'The repository is not installed yet' : 'Database unavailable' ?></h1>
        <?php if ($isSetup): ?>
          <p>The database <code>sjp2cd_repository</code> does not exist.</p>
          <p>Run the installer once to create it:
             <a href="<?= BASE_URL ?>install.php">Open the installer</a></p>
        <?php else: ?>
          <p>Could not connect to MySQL. Start <strong>MySQL</strong> in the XAMPP
             Control Panel, then reload this page.</p>
          <p><code><?= htmlspecialchars($e->getMessage()) ?></code></p>
        <?php endif; ?>
      </div>
    </body></html>
    <?php
    exit;
}
