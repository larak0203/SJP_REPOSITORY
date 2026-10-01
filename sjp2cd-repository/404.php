<?php
/**
 * Not found.
 *
 * Reached through the ErrorDocument rule in .htaccess. A mistyped URL should
 * land somewhere that explains itself and offers a way onward, rather than the
 * server's default page.
 */
require_once __DIR__ . '/config/config.php';

http_response_code(404);

/* What did they actually ask for? Useful for the reader and for you when
   someone reports a broken link. */
$asked = (string)($_SERVER['REDIRECT_URL'] ?? $_SERVER['REQUEST_URI'] ?? '');
$asked = strtok($asked, '?');

/* If the path looks like a record, offer a search for it rather than a shrug. */
$guess = null;
if (preg_match('/SJP2CD-\d{4}-\d{4}/i', $asked, $m)) {
    $g = $pdo->prepare("SELECT id, dc_title FROM records WHERE dc_identifier = ? LIMIT 1");
    $g->execute([strtoupper($m[0])]);
    $guess = $g->fetch() ?: null;
}

$published = (int)$pdo->query("SELECT COUNT(*) FROM records WHERE status = 'published'")->fetchColumn();

$pageTitle = 'Page not found';
$navActive = '';
include ROOT_PATH . '/templates/layout/header.php';
?>

<main id="main" class="section">
  <div class="container container-narrow center">

    <span class="eyebrow"><i data-ico="help" class="ico-sm"></i> 404</span>

    <h1 class="mt-4" style="font-size:var(--fs-3xl)">
      That page isn’t here.
    </h1>

    <p class="hero-lede mt-4" style="margin-inline:auto">
      <?php if ($asked !== ''): ?>
        Nothing in the repository answers to
        <span class="mono break-any"><?= e($asked) ?></span>.
        It may have been moved, or the link may be mistyped.
      <?php else: ?>
        The address you followed doesn’t match anything in the repository.
      <?php endif; ?>
    </p>

    <?php if ($guess): ?>
    <div class="alert alert-info mt-8" style="text-align:left">
      <i data-ico="info" class="ico-sm"></i>
      <span>
        That address contains a repository identifier, and the record exists.
        <a href="<?= url('record.php?id=' . (int)$guess['id']) ?>">Open
        “<?= e(mb_strimwidth((string)$guess['dc_title'], 0, 60, '…')) ?>”</a> instead.
      </span>
    </div>
    <?php endif; ?>

    <div class="hero-cta mt-8" style="justify-content:center">
      <a class="btn btn-primary btn-lg" href="<?= url('browse.php') ?>">
        <i data-ico="search" class="ico-sm"></i> Search the collection
      </a>
      <a class="btn btn-outline btn-lg" href="<?= url('index.php') ?>">
        <i data-ico="home" class="ico-sm"></i> Back to the repository
      </a>
    </div>

    <p class="small subtle mt-8">
      <?= number_format($published) ?> published record<?= $published === 1 ? '' : 's' ?> are searchable.
      If you followed a link from inside the repository, please tell the library:
      <span class="mono">repository@sjp2cd.edu.ph</span>
    </p>
  </div>
</main>

<?php include ROOT_PATH . '/templates/layout/footer.php'; ?>
