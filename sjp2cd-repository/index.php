<?php
require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/includes/metadata.php';

/* ---- what the collection actually holds ---- */
$published  = (int)$pdo->query("SELECT COUNT(*) FROM records WHERE status = 'published'")->fetchColumn();
$downloads  = (int)$pdo->query("SELECT COALESCE(SUM(downloads),0) FROM records")->fetchColumn();
$depositors = (int)$pdo->query("SELECT COUNT(DISTINCT submitted_by) FROM records")->fetchColumn();

$events     = (int)$pdo->query("SELECT COUNT(*) FROM premis_events")->fetchColumn();

/* ---- holdings by department ---- */
$byDept = $pdo->query(
    "SELECT d.id, d.name, d.code, COUNT(r.id) AS n
     FROM departments d
     LEFT JOIN records r ON r.department_id = d.id AND r.status = 'published'
     GROUP BY d.id HAVING n > 0 ORDER BY n DESC LIMIT 8"
)->fetchAll();

/* ---- newest published work ---- */
$recent = $pdo->query(
    "SELECT r.*, d.name AS department_name
     FROM records r LEFT JOIN departments d ON d.id = r.department_id
     WHERE r.status = 'published'
     ORDER BY r.published_at DESC, r.id DESC LIMIT 5"
)->fetchAll();

$navActive = 'home';
include ROOT_PATH . '/templates/layout/header.php';
?>

<main id="main">

<!-- ============================== HERO ============================== -->
<section class="hero" id="hero">
  <!-- Ambient background. The static gradient is always painted; hero3d.js
       upgrades it to a drifting Three.js scene only when the device, the
       viewport and the reader's motion preference all allow it. -->
  <div class="hero-bg" aria-hidden="true">
    <canvas class="hero-canvas" data-hero-canvas></canvas>
  </div>
  <div class="hero-scrim" aria-hidden="true"></div>

  <div class="container">
    <div class="hero-col">
      <span class="eyebrow"><i data-ico="sparkle" class="ico-sm"></i> Institutional repository</span>
      <h1 class="mt-4">The research of<br><span class="accent">St. John Paul II College of Davao</span></h1>
      <p class="hero-lede">
        Search theses, capstone projects and faculty research from every programme of the
        college. Free to read, and looked after so it still opens in 20 years.
      </p>

      <form class="hero-search" role="search" action="<?= url('browse.php') ?>" method="get">
        <label class="sr-only" for="q">Search the repository</label>
        <div class="input-icon grow">
          <i data-ico="search"></i>
          <input class="input" id="q" name="q" type="search" autocomplete="off"
                 data-suggest="<?= e(url('suggest.php')) ?>"
                 placeholder="Search titles, authors, subjects, abstracts">
        </div>
        <button class="btn btn-primary" type="submit">Search</button>
      </form>

      <div class="hero-cta">
        <a class="btn btn-accent btn-lg" href="<?= url('browse.php') ?>">Browse the collection <i data-ico="arrowright" class="ico-sm"></i></a>
        <?php if (canDeposit()): ?>
        <a class="btn btn-outline btn-lg" href="<?= url('submit.php') ?>"><i data-ico="upload" class="ico-sm"></i> Deposit your work</a>
        <?php endif; ?>
      </div>

      <div class="hero-meta">
        <div><div class="k" data-count="<?= $published ?>">0</div><div class="v">Published</div></div>
        <div><div class="k" data-count="<?= $depositors ?>">0</div><div class="v">Depositors</div></div>
        <div><div class="k" data-count="<?= $downloads ?>">0</div><div class="v">Downloads</div></div>
        <div><div class="k" data-count="<?= $events ?>">0</div><div class="v">Preservation events</div></div>
      </div>
    </div>
  </div>
</section>

<!-- ========================= BROWSE BY PROGRAMME ==================== -->
<!-- People look for research by the programme it came out of, long before they
     reach for a filter. This is the shortest path from the home page into the
     collection; the standards that describe it live on about.php. -->
<section class="section section-alt" id="programmes">
  <div class="container">
    <div class="section-head reveal">
      <h2 class="section-title">Browse by programme</h2>
      <p class="section-lede">
        Every college programme that has published work in the repository.
      </p>
    </div>

    <?php if ($byDept): ?>
    <div class="prog-grid mt-6">
      <?php foreach ($byDept as $d): ?>
      <a class="prog-card reveal" href="<?= url('browse.php?dept=' . (int)$d['id']) ?>">
        <span class="prog-code"><?= e($d['code'] ?: '') ?></span>
        <span class="prog-name"><?= e($d['name']) ?></span>
        <span class="prog-count"><?= (int)$d['n'] ?> <?= (int)$d['n'] === 1 ? 'work' : 'works' ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <div class="center mt-8">
      <a class="btn btn-outline" href="<?= url('browse.php') ?>">See the whole collection <i data-ico="arrowright" class="ico-sm"></i></a>
    </div>
    <?php else: ?>
    <div class="card empty reveal">
      <i data-ico="archive" class="ico-xl"></i>
      <h4>No published work yet</h4>
      <p class="mt-2">Programmes appear here as soon as the library publishes their first record.</p>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- ============================= RECENT ============================= -->
<section class="section section-alt" id="recent">
  <div class="container">
    <div class="row-between wrap mb-8">
      <div class="section-head" style="margin-bottom:0">
        <span class="eyebrow"><i data-ico="filestack" class="ico-sm"></i> Newest first</span>
        <h2 class="section-title">Recently published</h2>
      </div>
      <a class="btn btn-outline" href="<?= url('browse.php') ?>">Browse everything <i data-ico="arrowright" class="ico-sm"></i></a>
    </div>

    <?php if (!$recent): ?>
    <div class="card empty reveal">
      <i data-ico="archive" class="ico-xl"></i>
      <h4>Nothing published yet</h4>
      <p class="mt-2" style="max-width:52ch;margin-inline:auto">
        The collection starts with its first deposit. Faculty deposit finished work, and the
        library publishes it here once it has been reviewed.
      </p>
      <?php if (canDeposit()): ?>
      <a class="btn btn-primary mt-6" href="<?= url('submit.php') ?>">
        <i data-ico="upload" class="ico-sm"></i> Deposit the first record
      </a>
      <?php else: ?>
      <a class="btn btn-outline mt-6" href="<?= url('about.php') ?>">How deposits work</a>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="card" style="padding:0">
      <?php foreach ($recent as $r): $kw = splitList($r['dc_subject']); ?>
      <article class="result">
        <div class="row wrap mb-2" style="gap:8px">
          <span class="badge badge-brand"><?= e($r['dc_type']) ?></span>
          <?php if ($r['department_name']): ?><span class="badge"><?= e($r['department_name']) ?></span><?php endif; ?>
          <?= accessBadge((string)$r['access_level']) ?>
        </div>
        <h3><a href="<?= url('record.php?id=' . (int)$r['id']) ?>"><?= e($r['dc_title']) ?></a></h3>
        <div class="result-meta">
          <span><i data-ico="users" class="ico-sm"></i> <?= e($r['dc_creator']) ?></span>
          <span><i data-ico="calendar" class="ico-sm"></i> <?= humanDate($r['dc_date_issued']) ?></span>
          <span><i data-ico="download" class="ico-sm"></i> <?= number_format((int)$r['downloads']) ?></span>
        </div>
        <?php if ($r['dc_description']): ?>
        <p class="result-abs clamp-3"><?= e(mb_strimwidth((string)$r['dc_description'], 0, 300, '…')) ?></p>
        <?php endif; ?>
        <?php if ($kw): ?>
        <div class="row wrap" style="gap:8px">
          <?php foreach (array_slice($kw, 0, 5) as $k): ?>
          <a class="chip" href="<?= url('browse.php?q=' . urlencode($k)) ?>"><i data-ico="tag" class="ico-sm"></i><?= e($k) ?></a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="row mt-4 wrap" style="gap:8px">
          <a class="btn btn-outline btn-sm" href="<?= url('record.php?id=' . (int)$r['id']) ?>">Open record</a>
          <a class="btn btn-ghost btn-sm" href="<?= url('mets.php?id=' . (int)$r['id']) ?>" target="_blank" rel="noopener"><i data-ico="layers" class="ico-sm"></i> METS</a>
          <span class="mono xs subtle" style="margin-left:auto"><?= e($r['dc_identifier'] ?: '—') ?></span>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- =============================== CTA ============================== -->
<section class="section-sm">
  <div class="container">
    <div class="cta-band reveal">
      <h2>Work that nobody can find might as well not exist.</h2>
      <p>
        Every work here is described so it can be found, fingerprinted so any change shows,
        and re-checked so it is still whole years from now.
      </p>
      <div class="hero-cta">
        <?php if (canDeposit()): ?>
        <a class="btn btn-accent btn-lg" href="<?= url('submit.php') ?>">
          <i data-ico="upload" class="ico-sm"></i> Deposit your work
        </a>
        <a class="btn btn-outline btn-lg" href="<?= url('browse.php') ?>" style="--btn-fg:#fff;--btn-bd:rgba(255,255,255,.4)">Browse the collection</a>
        <?php else: ?>
        <a class="btn btn-accent btn-lg" href="<?= url('browse.php') ?>">Browse the collection</a>
        <a class="btn btn-outline btn-lg" href="<?= url('about.php') ?>" style="--btn-fg:#fff;--btn-bd:rgba(255,255,255,.4)">About the repository</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

</main>

<?php
/* The ambient hero background. Loaded only here, deferred, and it upgrades the
   static gradient in place — nothing on the page depends on it arriving. */
$extraFoot = '<script src="' . e(url('assets/js/hero3d.js')) . '?v=' . (@filemtime(ROOT_PATH . '/assets/js/hero3d.js') ?: time()) . '" defer></script>';
?>
<?php include ROOT_PATH . '/templates/layout/footer.php'; ?>
