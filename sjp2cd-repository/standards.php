<?php
require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/includes/metadata.php';

/* Everything on this page is counted from the live database, so the numbers
   are the system describing itself rather than a claim about it. */
$n = $pdo->query(
    "SELECT
      (SELECT COUNT(*) FROM records WHERE status='published')  records,
      (SELECT COUNT(*) FROM premis_objects)                    objects,
      (SELECT COUNT(*) FROM premis_events)                     events,
      (SELECT COUNT(DISTINCT event_type) FROM premis_events)   event_types,
      (SELECT COUNT(*) FROM premis_agents)                     agents,
      (SELECT COUNT(*) FROM premis_rights)                     rights,
      (SELECT COUNT(*) FROM mets_packages)                     packages,
      (SELECT COUNT(*) FROM mets_divisions)                    divisions,
      (SELECT COUNT(*) FROM mets_files)                        mets_files"
)->fetch();

/* Which Dublin Core elements are actually populated across the collection. */
$dcMap  = dublinCoreMap();
$req    = dublinCoreRequired();
$total  = (int)$pdo->query("SELECT COUNT(*) FROM records")->fetchColumn();
$fill   = [];
foreach ($dcMap as $element => [$col, $desc]) {
    $c = (int)$pdo->query("SELECT COUNT(*) FROM records WHERE $col IS NOT NULL AND $col <> ''")->fetchColumn();
    $fill[$element] = ['column' => $col, 'desc' => $desc, 'count' => $c,
                       'pct' => $total ? (int)round($c / $total * 100) : 0,
                       'required' => in_array($col, $req, true)];
}

$eventTypes = $pdo->query(
    "SELECT event_type, COUNT(*) n FROM premis_events GROUP BY event_type ORDER BY n DESC"
)->fetchAll();

$sample = $pdo->query("SELECT id, dc_identifier FROM records WHERE status='published' ORDER BY id LIMIT 1")->fetch();

$pageTitle = 'Metadata standards';
$navActive = 'standards';
include ROOT_PATH . '/templates/layout/header.php';
?>

<main id="main">

<section class="section-sm">
  <div class="container">
    <nav aria-label="Breadcrumb" class="mb-4">
      <ol class="row small subtle" style="gap:8px">
        <li><a href="<?= url('index.php') ?>">Home</a></li>
        <li aria-hidden="true">/</li>
        <li aria-current="page">Metadata standards</li>
      </ol>
    </nav>

    <div class="container-narrow" style="margin-inline:0">
      <h1 style="font-size:var(--fs-3xl)">Three standards, one deposit</h1>
      <p class="hero-lede mt-4">
        A repository is only as durable as what it records about its holdings. Every deposit here is described
        three times over: <strong>Dublin Core</strong> so it can be found, <strong>PREMIS</strong> so its history
        and integrity can be proven, and <strong>METS</strong> so all of that travels together as one package.
        The depositor fills in a single form. The rest is generated.
      </p>
    </div>

    <div class="stat-row mt-8">
      <article data-tilt class="stat stat-blue">
        <span class="stat-ico"><i data-ico="database"></i></span>
        <span class="stat-value">15</span>
        <span class="stat-label">Dublin Core elements</span>
        <span class="stat-foot">Stored as first-class columns, not a blob</span>
      </article>
      <article data-tilt class="stat stat-mint">
        <span class="stat-ico"><i data-ico="history"></i></span>
        <span class="stat-value"><?= number_format((int)$n['events']) ?></span>
        <span class="stat-label">PREMIS events recorded</span>
        <span class="stat-foot"><?= (int)$n['event_types'] ?> event types, <?= (int)$n['agents'] ?> agents</span>
      </article>
      <article data-tilt class="stat stat-gold">
        <span class="stat-ico"><i data-ico="layers"></i></span>
        <span class="stat-value"><?= number_format((int)$n['packages']) ?></span>
        <span class="stat-label">METS packages built</span>
        <span class="stat-foot"><?= number_format((int)$n['divisions']) ?> structural divisions</span>
      </article>
      <article data-tilt class="stat stat-lilac">
        <span class="stat-ico"><i data-ico="fingerprint"></i></span>
        <span class="stat-value"><?= DIGEST_LABEL ?></span>
        <span class="stat-label">Fixity algorithm</span>
        <span class="stat-foot">Computed at ingest, re-checked on demand</span>
      </article>
    </div>
  </div>
</section>

<section class="section-alt section-sm">
  <div class="container">
    <div class="section-head">
      <h2 class="section-title">What each standard is doing</h2>
      <p class="section-lede">They answer different questions, which is why a serious repository uses all three.</p>
    </div>

    <div class="std-grid">
      <article data-tilt class="std-card">
        <span class="feature-ico"><i data-ico="search"></i></span>
        <h3>Dublin Core</h3>
        <p class="std-tag mono">ISO 15836 · 15 elements</p>
        <p class="mt-3">Answers <strong>“how is this found?”</strong> Title, creator, subject, description, date, type,
           language and the rest — the fifteen elements every library catalogue and harvester understands.</p>
        <ul class="std-list mt-4">
          <li>Each element is its own indexed column, so search hits the metadata directly.</li>
          <li>Six are required before a deposit can be sent for review.</li>
          <li>Keywords are stored as a list and become browsable facets.</li>
        </ul>
      </article>

      <article data-tilt class="std-card">
        <span class="feature-ico"><i data-ico="shieldcheck"></i></span>
        <h3>PREMIS</h3>
        <p class="std-tag mono">Version 3.0 · 4 entities</p>
        <p class="mt-3">Answers <strong>“can this still be trusted?”</strong> It records the file as an Object, everything
           done to it as Events, who did it as Agents, and what may be done with it as Rights.</p>
        <ul class="std-list mt-4">
          <li>A <?= DIGEST_LABEL ?> digest is computed at ingest and stored with the object.</li>
          <li>Ingest, format identification, digest calculation, validation, publication and every download are logged.</li>
          <li>Re-running the digest is how the repository proves a file has not drifted.</li>
        </ul>
      </article>

      <article data-tilt class="std-card">
        <span class="feature-ico"><i data-ico="layers"></i></span>
        <h3>METS</h3>
        <p class="std-tag mono">Version 1.12 · 5 sections</p>
        <p class="mt-3">Answers <strong>“how does this move somewhere else?”</strong> One XML wrapper carrying the descriptive
           metadata, the preservation metadata, the file inventory and the structure of the document.</p>
        <ul class="std-list mt-4">
          <li><span class="mono">dmdSec</span> holds the Dublin Core; <span class="mono">amdSec</span> holds the PREMIS.</li>
          <li><span class="mono">fileSec</span> lists every stored file with its size and checksum.</li>
          <li><span class="mono">structMap</span> maps the chapters, so a reader can be sent to Chapter 3 directly.</li>
        </ul>
      </article>
    </div>

    <?php if ($sample): ?>
    <div class="card mt-8">
      <div class="row-between wrap" style="gap:var(--sp-4)">
        <div>
          <h3 class="panel-title">See it on a real record</h3>
          <p class="small muted mt-2">
            <?= e($sample['dc_identifier']) ?> — the same deposit shown as a catalogue entry and as a preservation package.
          </p>
        </div>
        <div class="row wrap" style="gap:8px">
          <a class="btn btn-outline" href="<?= url('record.php?id=' . (int)$sample['id']) ?>">Open the record</a>
          <a class="btn btn-primary" href="<?= url('mets.php?id=' . (int)$sample['id']) ?>" target="_blank" rel="noopener">
            <i data-ico="external" class="ico-sm"></i> View its METS XML
          </a>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>

<section class="section-sm">
  <div class="container">
    <div class="section-head">
      <h2 class="section-title">The fifteen elements, and how full they are</h2>
      <p class="section-lede">
        Measured across all <?= number_format($total) ?> record<?= $total === 1 ? '' : 's' ?> in the repository right now.
      </p>
    </div>

    <div class="table-wrap">
      <table class="tbl">
        <caption class="sr-only">Dublin Core elements, the column each maps to, and how many records carry it</caption>
        <thead>
          <tr>
            <th scope="col">Element</th>
            <th scope="col">What it records</th>
            <th scope="col">Stored as</th>
            <th scope="col">Required</th>
            <th scope="col">Populated</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($fill as $element => $f): ?>
        <tr>
          <td class="mono strong"><?= e($element) ?></td>
          <td class="small"><?= e($f['desc']) ?></td>
          <td class="mono xs subtle"><?= e($f['column']) ?></td>
          <td><?= $f['required']
                ? '<span class="badge badge-brand">Required</span>'
                : '<span class="badge">Optional</span>' ?></td>
          <td style="min-width:150px">
            <div class="hbar-row">
              <span class="hbar-track"><span class="hbar-fill" style="width:<?= (int)$f['pct'] ?>%"></span></span>
              <span class="xs subtle" style="white-space:nowrap"><?= (int)$f['count'] ?>/<?= (int)$total ?></span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<?php if ($eventTypes): ?>
<section class="section-alt section-sm">
  <div class="container">
    <div class="section-head">
      <h2 class="section-title">Preservation events, as actually logged</h2>
      <p class="section-lede">Every one of these was written by the system doing the thing it describes.</p>
    </div>

    <div class="grid-2" style="align-items:start">
      <div class="card">
        <table class="tbl">
          <caption class="sr-only">PREMIS event types and how many times each has occurred</caption>
          <thead><tr><th scope="col">Event type</th><th scope="col">When it fires</th><th scope="col">Count</th></tr></thead>
          <tbody>
          <?php
          $when = [
            'ingest'                     => 'A file is accepted into storage',
            'format identification'      => 'The media type is determined and recorded',
            'message digest calculation' => 'The ' . DIGEST_LABEL . ' checksum is computed',
            'validation'                 => 'A reviewer approves or returns the work',
            'submission'                 => 'A depositor sends work for review',
            'publication'                => 'The library releases the record publicly',
            'dissemination'              => 'Someone downloads the full text',
            'fixity check'               => 'A stored digest is recomputed and compared',
          ];
          foreach ($eventTypes as $et): ?>
          <tr>
            <td class="mono small"><?= e($et['event_type']) ?></td>
            <td class="small subtle"><?= e($when[$et['event_type']] ?? 'Recorded by the repository') ?></td>
            <td class="strong"><?= number_format((int)$et['n']) ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card">
        <h3 class="panel-title mb-4">The crosswalk</h3>
        <p class="small muted mb-4">How one deposited fact lands in all three standards at once.</p>
        <table class="tbl">
          <caption class="sr-only">Crosswalk between the deposit form, Dublin Core, PREMIS and METS</caption>
          <thead><tr><th scope="col">On the form</th><th scope="col">Dublin Core</th><th scope="col">METS section</th></tr></thead>
          <tbody>
            <tr><td class="small">Title</td><td class="mono xs">dc.title</td><td class="mono xs">dmdSec</td></tr>
            <tr><td class="small">Authors</td><td class="mono xs">dc.creator</td><td class="mono xs">dmdSec</td></tr>
            <tr><td class="small">Adviser</td><td class="mono xs">dc.contributor</td><td class="mono xs">dmdSec</td></tr>
            <tr><td class="small">Keywords</td><td class="mono xs">dc.subject</td><td class="mono xs">dmdSec</td></tr>
            <tr><td class="small">Abstract</td><td class="mono xs">dc.description</td><td class="mono xs">dmdSec</td></tr>
            <tr><td class="small">Access level</td><td class="mono xs">dc.rights</td><td class="mono xs">amdSec / rightsMD</td></tr>
            <tr><td class="small">The uploaded file</td><td class="mono xs">dc.format</td><td class="mono xs">fileSec + amdSec</td></tr>
            <tr><td class="small">Chapter list</td><td class="subtle xs">—</td><td class="mono xs">structMap</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="cta-band">
  <div class="container center">
    <h2 class="section-title">Your work, described properly, from the day you deposit it</h2>
    <p class="section-lede mt-3">You fill in one form. The repository handles the standards.</p>
    <div class="hero-cta mt-6" style="justify-content:center">
      <?php if (canDeposit()): ?>
      <a class="btn btn-accent btn-lg" href="<?= url('submit.php') ?>">
        <i data-ico="upload" class="ico-sm"></i> Deposit your work
      </a>
      <?php else: ?>
      <a class="btn btn-accent btn-lg" href="<?= url('browse.php') ?>">
        <i data-ico="search" class="ico-sm"></i> Browse the collection
      </a>
      <?php endif; ?>
      <a class="btn btn-outline btn-lg" href="<?= url('preservation.php') ?>">
        <i data-ico="shieldcheck" class="ico-sm"></i> See the preservation report
      </a>
    </div>
  </div>
</section>

</main>

<?php include ROOT_PATH . '/templates/layout/footer.php'; ?>
