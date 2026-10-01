<?php
/* ============================================================================
   One-time installer.

   Creates the database from sql/schema.sql, seeds departments and PREMIS
   software agents, and creates starter accounts with properly hashed
   passwords (which is why seeding happens here rather than in SQL).

   Visit  /sjp2cd-repository/install.php  once, then delete this file.
   ============================================================================ */

declare(strict_types=1);

$ROOT = __DIR__;
$done = [];
$errors = [];
$ranSetup = false;

$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'sjp2cd_repository';

$STARTER_PASSWORD = 'Sjp2cd!2026';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ranSetup = true;
    try {
        /* ---- 1. schema ---- */
        $root = new PDO("mysql:host={$DB_HOST};charset=utf8mb4", $DB_USER, $DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $sql = file_get_contents($ROOT . '/sql/schema.sql');
        if ($sql === false) throw new RuntimeException('Could not read sql/schema.sql');
        $root->exec($sql);
        $done[] = 'Database <code>' . $DB_NAME . '</code> created with 18 tables.';

        $pdo = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4", $DB_USER, $DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

        /* ---- 2. departments ---- */
        $departments = [
            ['CCS',   'College of Computer Studies'],
            ['CED',   'College of Education'],
            ['CBA',   'College of Business Administration'],
            ['CN',    'College of Nursing'],
            ['CAS',   'College of Arts and Sciences'],
            ['CENG',  'College of Engineering'],
            ['CPSY',  'College of Psychology'],
            ['CCRIM', 'College of Criminology'],
            ['CACC',  'College of Accountancy'],
        ];
        $ins = $pdo->prepare("INSERT INTO departments (code, name) VALUES (?, ?)");
        foreach ($departments as $d) { $ins->execute($d); }
        $done[] = count($departments) . ' departments added.';

        /* ---- 3. PREMIS software / organisation agents ---- */
        $agents = [
            ['svc:repository', 'SJP2CD Repository Service',         'software'],
            ['svc:hasher',     'PHP hash_file (SHA-256)',           'software'],
            ['svc:auditor',    'Scheduled fixity auditor',          'software'],
            ['org:sjp2cd',     'St. John Paul II College of Davao', 'organization'],
        ];
        $insA = $pdo->prepare(
            "INSERT INTO premis_agents (agent_identifier, agent_name, agent_type) VALUES (?, ?, ?)"
        );
        foreach ($agents as $a) { $insA->execute($a); }
        $done[] = count($agents) . ' PREMIS agents registered.';

        /* ---- 4. starter accounts ---- */
        $hash = password_hash($STARTER_PASSWORD, PASSWORD_DEFAULT);

        $insU = $pdo->prepare(
            "INSERT INTO users (name, email, password_hash, role, department_id, student_number, adviser_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );

        $insU->execute(['Repository Administrator', 'admin@sjp2cd.edu.ph',     $hash, 'admin',   1, null, null]);
        $insU->execute(['Dr. Ramon C. Villareal',   'villareal@sjp2cd.edu.ph', $hash, 'faculty', 1, null, null]);
        $adviser1 = (int)$pdo->lastInsertId();
        $insU->execute(['Prof. Elena B. Cortez',    'cortez@sjp2cd.edu.ph',    $hash, 'faculty', 2, null, null]);
        $adviser2 = (int)$pdo->lastInsertId();

        $insU->execute(['Althea M. Ronquillo', 'ronquillo@sjp2cd.edu.ph', $hash, 'student', 1, '2022-01847', $adviser1]);
        $insU->execute(['Jerome L. Vasquez',   'vasquez@sjp2cd.edu.ph',   $hash, 'student', 2, '2022-02310', $adviser2]);

        $done[] = '5 starter accounts created (1 admin, 2 faculty, 2 students with advisers).';

        /* ---- 5. writable folders ---- */
        foreach (['/uploads/records', '/uploads/avatars'] as $dir) {
            if (!is_dir($ROOT . $dir)) { @mkdir($ROOT . $dir, 0775, true); }
            if (!is_writable($ROOT . $dir)) { $errors[] = "Folder <code>{$dir}</code> is not writable."; }
        }
        if (!$errors) { $done[] = 'Upload folders are present and writable.'; }

    } catch (Throwable $ex) {
        $errors[] = htmlspecialchars($ex->getMessage());
    }
}

/* Is it already installed? */
$installed = false;
try {
    $probe = new PDO("mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4", $DB_USER, $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $installed = (int)$probe->query("SELECT COUNT(*) FROM users")->fetchColumn() > 0;
} catch (Throwable) { /* not installed yet */ }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install — SJP2CD Institutional Repository</title>
<link rel="icon" href="assets/img/logo.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700&family=JetBrains+Mono:wght@400;600&display=swap">
<link rel="stylesheet" href="assets/css/design-system.css">
</head>
<body>
<main id="main" class="section-sm">
  <div class="container container-narrow">

    <div class="row mb-8" style="gap:var(--sp-3)">
      <span class="brand-logo"><img src="assets/img/logo.svg" alt=""></span>
      <div>
        <h1 style="font-size:var(--fs-2xl)">SJP2CD Institutional Repository</h1>
        <p class="muted mt-1">First-time setup</p>
      </div>
    </div>

    <?php if ($ranSetup && !$errors): ?>
      <div class="alert alert-ok mb-6">
        <i data-ico="checkcircle" class="ico-sm"></i>
        <div class="grow"><strong>Installation complete</strong>
          <ul class="mt-2" style="list-style:disc;padding-left:18px">
            <?php foreach ($done as $d): ?><li class="small"><?= $d ?></li><?php endforeach; ?>
          </ul>
        </div>
      </div>

      <div class="card mb-6">
        <h3>Starter accounts</h3>
        <p class="small muted mt-2">All use the password <code class="mono"><?= htmlspecialchars($STARTER_PASSWORD) ?></code>. Change them after signing in.</p>
        <div class="table-wrap mt-4">
          <table class="tbl">
            <thead><tr><th>Role</th><th>Email</th><th>Notes</th></tr></thead>
            <tbody>
              <tr><td><span class="badge badge-brand">Admin</span></td><td class="mono">admin@sjp2cd.edu.ph</td><td>Publishes records, manages users, runs preservation</td></tr>
              <tr><td><span class="badge badge-gold">Faculty</span></td><td class="mono">villareal@sjp2cd.edu.ph</td><td>Adviser to Althea Ronquillo</td></tr>
              <tr><td><span class="badge badge-gold">Faculty</span></td><td class="mono">cortez@sjp2cd.edu.ph</td><td>Adviser to Jerome Vasquez</td></tr>
              <tr><td><span class="badge badge-ok">Student</span></td><td class="mono">ronquillo@sjp2cd.edu.ph</td><td>Submissions route to Dr. Villareal</td></tr>
              <tr><td><span class="badge badge-ok">Student</span></td><td class="mono">vasquez@sjp2cd.edu.ph</td><td>Submissions route to Prof. Cortez</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="alert alert-warn mb-6">
        <i data-ico="alert" class="ico-sm"></i>
        <span><strong>Delete this file now.</strong>Remove <code class="mono">install.php</code> so it cannot be run again — re-running it would drop the database.</span>
      </div>

      <a class="btn btn-primary btn-lg" href="index.php">Open the repository</a>

    <?php elseif ($errors): ?>
      <div class="alert alert-danger mb-6">
        <i data-ico="alert" class="ico-sm"></i>
        <div class="grow"><strong>Installation failed</strong>
          <ul class="mt-2" style="list-style:disc;padding-left:18px">
            <?php foreach ($errors as $er): ?><li class="small"><?= $er ?></li><?php endforeach; ?>
          </ul>
        </div>
      </div>
      <a class="btn btn-outline" href="install.php">Try again</a>

    <?php else: ?>
      <?php if ($installed): ?>
      <div class="alert alert-warn mb-6">
        <i data-ico="alert" class="ico-sm"></i>
        <span><strong>Already installed.</strong>Running the installer again will <strong>drop the database and delete every record</strong>. Only continue if you mean to start over.</span>
      </div>
      <?php endif; ?>

      <div class="card">
        <h3>What this will do</h3>
        <ul class="std-list mt-4">
          <li><i data-ico="check" class="ico-sm"></i> <span>Create the <code class="mono">sjp2cd_repository</code> database and its 18 tables</span></li>
          <li><i data-ico="check" class="ico-sm"></i> <span>Add 9 departments and the PREMIS software agents</span></li>
          <li><i data-ico="check" class="ico-sm"></i> <span>Create 5 starter accounts across the three roles</span></li>
          <li><i data-ico="check" class="ico-sm"></i> <span>Check the upload folders are writable</span></li>
        </ul>
        <p class="hint mt-4">Your existing <code class="mono">institutional_repository</code> database is not touched.</p>

        <form method="post" class="mt-6">
          <button class="btn <?= $installed ? 'btn-danger' : 'btn-primary' ?> btn-lg" type="submit"
                  <?= $installed ? 'onclick="return confirm(\'This will DROP the existing sjp2cd_repository database and delete all its records. Continue?\')"' : '' ?>>
            <i data-ico="database" class="ico-sm"></i>
            <?= $installed ? 'Reinstall (destroys data)' : 'Install now' ?>
          </button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</main>

<script src="assets/js/icons.js"></script>
<script src="assets/js/ui.js"></script>
</body>
</html>
