<?php
require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/includes/metadata.php';

$q      = trim((string)($_GET['q'] ?? ''));
$type   = (string)($_GET['type'] ?? '');
$dept   = (int)($_GET['dept'] ?? 0);
$year   = (string)($_GET['year'] ?? '');
$author = trim((string)($_GET['author'] ?? ''));
$subject = trim((string)($_GET['subject'] ?? ''));
$sort   = (string)($_GET['sort'] ?? 'newest');
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 10;

/* The archive holds work past the five-year cut-off. It is a separate view
   rather than a filter mixed into the main listing, so the catalogue shows
   current research by default and older work stays deliberately reachable. */
$archive = ($_GET['view'] ?? '') === 'archive';
$state   = $archive ? 'archived' : 'published';

$where  = ["r.status = ?"];
$params = [$state];

if ($q !== '') {
    $where[] = "(r.dc_title LIKE ? OR r.dc_creator LIKE ? OR r.dc_subject LIKE ? OR r.dc_description LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($type !== '' && in_array($type, DOC_TYPES, true)) { $where[] = "r.dc_type = ?";      $params[] = $type; }
if ($dept > 0)                                        { $where[] = "r.department_id = ?"; $params[] = $dept; }
if ($year !== '' && ctype_digit($year))               { $where[] = "r.year_completed = ?"; $params[] = (int)$year; }
/* dc_creator carries a semicolon-separated list, so an author match looks
   inside it rather than comparing the whole field. */
if ($author !== '')                                   { $where[] = "r.dc_creator LIKE ?"; $params[] = '%' . $author . '%'; }
/* dc_subject is a semicolon-separated list too, so the same containment match
   applies -- bounded by the separators so "nursing" cannot match "e-nursing". */
if ($subject !== '') {
    /* Separators are normalised on both sides before matching, so a keyword
       typed as "a;b" finds the same records as one stored as "a; b", and the
       bounding semicolons stop "nursing" matching "paediatric nursing". */
    $where[]  = "CONCAT(';', REPLACE(REPLACE(r.dc_subject, ' ;', ';'), '; ', ';'), ';') LIKE ?";
    $params[] = '%;' . str_replace(['; ', ' ;'], ';', $subject) . ';%';
}

$sql = implode(' AND ', $where);

$order = match ($sort) {
    'oldest'    => 'r.published_at ASC',
    'title'     => 'r.dc_title ASC',
    'downloads' => 'r.downloads DESC',
    default     => 'r.published_at DESC, r.id DESC',
};

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM records r WHERE $sql");
$countStmt->execute($params);
$total  = (int)$countStmt->fetchColumn();
$pages  = max(1, (int)ceil($total / $per));
$page   = min($page, $pages);
$offset = ($page - 1) * $per;

$rows = $pdo->prepare(
    "SELECT r.*, d.name AS department_name
     FROM records r LEFT JOIN departments d ON d.id = r.department_id
     WHERE $sql ORDER BY $order LIMIT $per OFFSET $offset"
);
$rows->execute($params);
$records = $rows->fetchAll();

/* facet counts, scoped to published records only */
$typeCounts = $pdo->prepare(
    "SELECT dc_type, COUNT(*) n FROM records WHERE status = ? GROUP BY dc_type ORDER BY n DESC"
);
$typeCounts->execute([$state]);
$typeCounts = $typeCounts->fetchAll();
$deptCounts = $pdo->prepare(
    "SELECT d.id, d.name, d.code, COUNT(r.id) n
     FROM departments d JOIN records r ON r.department_id = d.id AND r.status = ?
     GROUP BY d.id ORDER BY n DESC"
);
$deptCounts->execute([$state]);
$deptCounts = $deptCounts->fetchAll();
$yearCounts = $pdo->prepare(
    "SELECT year_completed y, COUNT(*) n FROM records
     WHERE status = ? AND year_completed IS NOT NULL GROUP BY y ORDER BY y DESC LIMIT 8"
);
$yearCounts->execute([$state]);
$yearCounts = $yearCounts->fetchAll();

/* Browse by author, as the conceptual framework describes. dc_creator holds a
   list per record, so it is split here and the names counted individually —
   otherwise "Cruz; Santos" would be a different author from "Cruz". */
$authorRows = $pdo->prepare("SELECT dc_creator FROM records WHERE status = ?");
$authorRows->execute([$state]);
$authorCounts = [];
foreach ($authorRows->fetchAll() as $row) {
    foreach (splitList($row['dc_creator']) as $name) {
        $name = trim($name);
        if ($name === '') continue;
        $authorCounts[$name] = ($authorCounts[$name] ?? 0) + 1;
    }
}
arsort($authorCounts);
$authorShown = 8;                              /* the rest fold away until asked for */
$authorCounts = array_slice($authorCounts, 0, 60, true);

/* A selected author must be visible even if they sit deep in the tail. */
if ($author !== '' && isset($authorCounts[$author])) {
    $n = $authorCounts[$author];
    unset($authorCounts[$author]);
    $authorCounts = [$author => $n] + $authorCounts;
}

function browseUrl(array $over = []): string {
    $p = array_merge($_GET, $over);
    $p = array_filter($p, static fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
    return url('browse.php') . ($p ? '?' . http_build_query($p) : '');
}
function mark(string $text, string $term): string {
    $safe = e($text);
    if ($term === '') return $safe;
    return preg_replace('/(' . preg_quote(e($term), '/') . ')/i', '<mark>$1</mark>', $safe);
}

$active = [];
if ($q !== '')    $active[] = ['q',    '“' . $q . '”'];
if ($type !== '') $active[] = ['type', $type];
if ($dept > 0)    foreach ($deptCounts as $d) if ((int)$d['id'] === $dept) $active[] = ['dept', $d['name']];
if ($year !== '')   $active[] = ['year', $year];
if ($author !== '') $active[] = ['author', $author];
if ($subject !== '') $active[] = ['subject', $subject];

$viewCounts = $pdo->query(
    "SELECT SUM(status='published') live, SUM(status='archived') archived FROM records"
)->fetch();

$pageTitle = $archive ? 'Archive' : 'Browse';
$navActive = 'browse';
include ROOT_PATH . '/templates/layout/header.php';
?>

<main id="main" class="section-sm">
  <div class="container">

    <nav aria-label="Breadcrumb" class="mb-4">
      <ol class="row small subtle" style="gap:8px">
        <li><a href="<?= url('index.php') ?>">Home</a></li>
        <li aria-hidden="true">/</li>
        <li aria-current="page"><?= $archive ? 'Archive' : 'Browse' ?></li>
      </ol>
    </nav>

    <div class="row-between wrap mb-6">
      <div>
        <h1 style="font-size:var(--fs-2xl)"><?= $archive ? 'The archive' : 'Browse the collection' ?></h1>
        <p class="muted mt-2">
          <?php if ($archive): ?>
            Work completed more than <?= ARCHIVE_AFTER_YEARS ?> years ago. These records keep their
            identifier, metadata and files — they are simply no longer listed in the main catalogue.
          <?php else: ?>
            <?= number_format($total) ?> published record<?= $total === 1 ? '' : 's' ?><?php
            if ($deptCounts) echo ' from ' . count($deptCounts) . ' programme' . (count($deptCounts) === 1 ? '' : 's'); ?>.
          <?php endif; ?>
        </p>
      </div>
      <?php if (canDeposit()): ?>
      <a class="btn btn-accent" href="<?= url('submit.php') ?>">
        <i data-ico="upload" class="ico-sm"></i> Deposit your work
      </a>
      <?php endif; ?>
    </div>

    <div class="chips mb-4" aria-label="Which collection to browse">
      <a class="chip <?= $archive ? '' : 'is-active' ?>" href="<?= url('browse.php') ?>">
        <i data-ico="book" class="ico-sm"></i> Current catalogue
        <span class="facet-count"><?= number_format((int)$viewCounts['live']) ?></span>
      </a>
      <a class="chip <?= $archive ? 'is-active' : '' ?>" href="<?= url('browse.php?view=archive') ?>">
        <i data-ico="archive" class="ico-sm"></i> Archive
        <span class="facet-count"><?= number_format((int)$viewCounts['archived']) ?></span>
      </a>
    </div>

    <form class="card card-tight mb-6" role="search" method="get" action="<?= url('browse.php') ?>">
      <?php if ($archive): ?><input type="hidden" name="view" value="archive"><?php endif; ?>
      <div class="row wrap" style="gap:var(--sp-3)">
        <div class="input-icon grow" style="min-width:260px">
          <i data-ico="search"></i>
          <label class="sr-only" for="q">Search</label>
          <input class="input" id="q" name="q" type="search" value="<?= e($q) ?>"
                 data-suggest="<?= e(url('suggest.php')) ?>"
                 placeholder="Search titles, authors, keywords, abstracts" autocomplete="off">
        </div>
        <?php foreach (['type' => $type, 'dept' => $dept ?: '', 'year' => $year, 'author' => $author, 'subject' => $subject] as $k => $v): ?>
          <?php if ($v !== '' && $v !== 0): ?><input type="hidden" name="<?= $k ?>" value="<?= e((string)$v) ?>"><?php endif; ?>
        <?php endforeach; ?>
        <label class="sr-only" for="sort">Order</label>
        <select class="select" id="sort" name="sort" style="width:auto;min-width:190px" onchange="this.form.submit()">
          <option value="newest"    <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
          <option value="oldest"    <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest first</option>
          <option value="title"     <?= $sort === 'title' ? 'selected' : '' ?>>Title A–Z</option>
          <option value="downloads" <?= $sort === 'downloads' ? 'selected' : '' ?>>Most downloaded</option>
        </select>
        <button class="btn btn-primary" type="submit">Search</button>
      </div>
    </form>

    <div class="results-layout">
      <aside class="card filters" aria-label="Narrow the results">
        <div class="row-between mb-2">
          <span class="panel-title" style="font-size:var(--fs-md)">Narrow it down</span>
          <?php if ($active): ?><a class="btn btn-ghost btn-sm" href="<?= url('browse.php') ?>">Clear</a><?php endif; ?>
        </div>

        <?php if ($typeCounts): ?>
        <div class="facet">
          <button class="facet-head" type="button" aria-expanded="true" aria-controls="f-type"><span>Kind of work</span><i data-ico="chevdown" class="ico-sm"></i></button>
          <div class="facet-body" id="f-type">
            <?php foreach ($typeCounts as $t): ?>
            <a class="facet-opt" href="<?= browseUrl(['type' => $type === $t['dc_type'] ? '' : $t['dc_type'], 'page' => '']) ?>">
              <input type="checkbox" <?= $type === $t['dc_type'] ? 'checked' : '' ?> tabindex="-1" aria-hidden="true">
              <span class="truncate"><?= e($t['dc_type']) ?></span>
              <span class="facet-count"><?= (int)$t['n'] ?></span>
            </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($deptCounts): ?>
        <div class="facet">
          <button class="facet-head" type="button" aria-expanded="true" aria-controls="f-dept"><span>Department</span><i data-ico="chevdown" class="ico-sm"></i></button>
          <div class="facet-body" id="f-dept">
            <?php foreach ($deptCounts as $d): ?>
            <a class="facet-opt" href="<?= browseUrl(['dept' => $dept === (int)$d['id'] ? '' : (int)$d['id'], 'page' => '']) ?>">
              <input type="checkbox" <?= $dept === (int)$d['id'] ? 'checked' : '' ?> tabindex="-1" aria-hidden="true">
              <span class="truncate" title="<?= e($d['name']) ?>"><?= e($d['code'] ?: $d['name']) ?></span>
              <span class="facet-count"><?= (int)$d['n'] ?></span>
            </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($yearCounts): ?>
        <div class="facet">
          <button class="facet-head" type="button" aria-expanded="true" aria-controls="f-year"><span>Year</span><i data-ico="chevdown" class="ico-sm"></i></button>
          <div class="facet-body" id="f-year">
            <?php foreach ($yearCounts as $y): ?>
            <a class="facet-opt" href="<?= browseUrl(['year' => $year === (string)$y['y'] ? '' : (string)$y['y'], 'page' => '']) ?>">
              <input type="checkbox" <?= $year === (string)$y['y'] ? 'checked' : '' ?> tabindex="-1" aria-hidden="true">
              <span class="truncate"><?= (int)$y['y'] ?></span>
              <span class="facet-count"><?= (int)$y['n'] ?></span>
            </a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($authorCounts): ?>
        <div class="facet">
          <button class="facet-head" type="button" aria-expanded="true" aria-controls="f-author"><span>Author</span><i data-ico="chevdown" class="ico-sm"></i></button>
          <div class="facet-body" id="f-author" data-facet-filter>
            <?php if (count($authorCounts) > $authorShown): ?>
            <label class="sr-only" for="author-find">Find an author in this list</label>
            <input class="input input-sm facet-find" id="author-find" type="search"
                   placeholder="Find an author" autocomplete="off" data-facet-find>
            <?php endif; ?>

            <div data-facet-list>
              <?php $i = 0; foreach ($authorCounts as $name => $n): $i++; ?>
              <a class="facet-opt<?= $i > $authorShown ? ' is-folded' : '' ?>"
                 href="<?= browseUrl(['author' => $author === $name ? '' : $name, 'page' => '']) ?>">
                <input type="checkbox" <?= $author === $name ? 'checked' : '' ?> tabindex="-1" aria-hidden="true">
                <span class="truncate" title="<?= e($name) ?>"><?= e($name) ?></span>
                <span class="facet-count"><?= (int)$n ?></span>
              </a>
              <?php endforeach; ?>
            </div>

            <?php if (count($authorCounts) > $authorShown): ?>
            <button class="facet-more" type="button" data-facet-more
                    aria-expanded="false"
                    data-less="Show fewer"
                    data-more="Show all <?= count($authorCounts) ?> authors">Show all <?= count($authorCounts) ?> authors</button>
            <p class="facet-none" data-facet-none hidden>No author matches that.</p>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </aside>

      <div>
        <div class="row-between wrap mb-3">
          <p class="small muted" role="status" aria-live="polite">
            <?php if ($total === 0): ?>Nothing matches those filters.
            <?php else: ?><?= number_format($total) ?> record<?= $total === 1 ? '' : 's' ?><?= $q !== '' ? ' matching “' . e($q) . '”' : '' ?>,
              showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $per, $total)) ?>.
            <?php endif; ?>
          </p>
          <?php if ($active): ?>
          <div class="chips" aria-label="Filters in use">
            <?php foreach ($active as $a): ?>
            <a class="chip is-active" href="<?= browseUrl([$a[0] => '', 'page' => '']) ?>">
              <?= e($a[1]) ?><i data-ico="close" class="ico-sm" data-label="Remove this filter"></i>
            </a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <?php if (!$records): ?>
        <div class="card">
          <div class="empty">
            <i data-ico="search" class="ico-xl"></i>
            <h4><?= $total === 0 && !$active
                  ? ($archive ? 'The archive is empty' : 'The collection is empty')
                  : 'Nothing matches' ?></h4>
            <p class="mt-2" style="max-width:48ch;margin-inline:auto">
              <?= $total === 0 && !$active
                  ? ($archive
                      ? 'Records move here automatically once they pass ' . ARCHIVE_AFTER_YEARS . ' years. Nothing has yet.'
                      : 'Once a deposit is approved and published it appears here.')
                  : 'Try removing a filter or searching a broader term.' ?>
            </p>
            <?php if ($active): ?>
            <a class="btn btn-outline mt-6" href="<?= url('browse.php') ?>">Clear the filters</a>
            <?php elseif (canDeposit()): ?>
            <a class="btn btn-outline mt-6" href="<?= url('submit.php') ?>">Deposit the first record</a>
            <?php else: ?>
            <a class="btn btn-outline mt-6" href="<?= url('about.php') ?>">How deposits work</a>
            <?php endif; ?>
          </div>
        </div>
        <?php else: ?>
        <div class="card" style="padding:0">
          <?php foreach ($records as $r): $kw = splitList($r['dc_subject']); ?>
          <article class="result">
            <div class="row wrap mb-2" style="gap:8px">
              <span class="badge badge-brand"><?= e($r['dc_type']) ?></span>
              <?php if ($r['department_name']): ?><span class="badge"><?= e($r['department_name']) ?></span><?php endif; ?>
              <?= accessBadge((string)$r['access_level']) ?>
            </div>
            <h3><a href="<?= url('record.php?id=' . (int)$r['id']) ?>"><?= mark((string)$r['dc_title'], $q) ?></a></h3>
            <div class="result-meta">
              <span><i data-ico="users" class="ico-sm"></i><?php
                $names = splitList($r['dc_creator']);
                $links = [];
                foreach ($names as $nm) {
                    $links[] = '<a href="' . e(browseUrl(['author' => $nm, 'page' => '', 'q' => ''])) . '">'
                             . mark($nm, $q) . '</a>';
                }
                echo ' ' . ($links ? implode('; ', $links) : mark((string)$r['dc_creator'], $q));
              ?></span>
              <span><i data-ico="calendar" class="ico-sm"></i> <?= humanDate($r['dc_date_issued']) ?></span>
              <span><i data-ico="download" class="ico-sm"></i> <?= number_format((int)$r['downloads']) ?></span>
            </div>
            <?php if ($r['dc_description']): ?>
            <p class="result-abs clamp-3"><?= e(mb_strimwidth((string)$r['dc_description'], 0, 300, '…')) ?></p>
            <?php endif; ?>
            <?php if ($kw): ?>
            <div class="row wrap" style="gap:8px">
              <?php foreach (array_slice($kw, 0, 5) as $k): ?>
              <a class="chip<?= $subject === $k ? ' is-active' : '' ?>" href="<?= browseUrl(['subject' => $subject === $k ? '' : $k, 'page' => '', 'q' => '']) ?>"><i data-ico="tag" class="ico-sm"></i><?= e($k) ?></a>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="row mt-4 wrap" style="gap:8px">
              <a class="btn btn-outline btn-sm" href="<?= url('record.php?id=' . (int)$r['id']) ?>">Open record</a>
              <span class="mono xs subtle" style="margin-left:auto"><?= e($r['dc_identifier'] ?: '') ?></span>
            </div>
          </article>
          <?php endforeach; ?>
        </div>

        <?php if ($pages > 1): ?>
        <nav class="row-between mt-6 wrap" aria-label="Pages" style="gap:var(--sp-3)">
          <?php if ($page > 1): ?>
          <a class="btn btn-outline btn-sm" href="<?= browseUrl(['page' => $page - 1]) ?>"><i data-ico="chevleft" class="ico-sm"></i> Previous</a>
          <?php else: ?><span></span><?php endif; ?>
          <p class="small subtle">Page <?= $page ?> of <?= $pages ?></p>
          <?php if ($page < $pages): ?>
          <a class="btn btn-outline btn-sm" href="<?= browseUrl(['page' => $page + 1]) ?>">Next <i data-ico="chevright" class="ico-sm"></i></a>
          <?php else: ?><span></span><?php endif; ?>
        </nav>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</main>

<?php include ROOT_PATH . '/templates/layout/footer.php'; ?>
