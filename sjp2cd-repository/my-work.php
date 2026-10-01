<?php
require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/includes/metadata.php';
requireLogin();
if (isGuest()) { flash('info', 'Deposits are made by faculty and the library. Your account is for reading the catalogue.'); redirect('browse.php'); }

$uid = currentUserId();

/* ---- actions on your own deposits ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $id  = (int)($_POST['id'] ?? 0);
    $act = (string)($_POST['action'] ?? '');

    $own = $pdo->prepare("SELECT * FROM records WHERE id = ? AND submitted_by = ?");
    $own->execute([$id, $uid]);
    $rec = $own->fetch();

    if (!$rec) {
        flash('danger', 'That deposit is not yours to change.');
    } elseif ($act === 'send' && in_array($rec['status'], ['draft', 'revision'], true)) {
        $next = canPublish() ? 'approved' : 'submitted';
        $pdo->prepare("UPDATE records SET status = ?, submitted_at = NOW() WHERE id = ?")->execute([$next, $id]);
        premisEvent($pdo, $id, 'submission', 'success',
            'Sent for review by the depositor', premisAgentForUser($pdo, $uid, currentUser($pdo)['name']));
        if ($rec['adviser_id']) {
            notify($pdo, (int)$rec['adviser_id'], 'A deposit needs your review',
                   $rec['dc_title'], 'review', $id);
        } else {
            notifyLibrary($pdo, 'A deposit needs review', $rec['dc_title'], 'review', $id);
        }
        logActivity($pdo, $uid, 'sent a deposit for review', $rec['dc_title'], $id);
        flash('ok', 'Sent for review. You will be notified when there is a decision.');
    } elseif ($act === 'unpublish' && $rec['status'] === 'published' && canDeposit()) {
        /* Taking a published work down to revise it. Not a deletion: the record,
           its identifier and every file stay. It leaves the catalogue, and its
           address shows that it is being revised rather than breaking. */
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($reason === '') {
            flash('warn', 'Say what you are revising, so the library knows why it came down.');
        } else {
            $pdo->prepare("UPDATE records SET status = 'revision' WHERE id = ?")->execute([$id]);
            premisEvent($pdo, $id, 'withdrawal', 'success',
                'Temporarily withdrawn by the depositor for revision: ' . mb_strimwidth($reason, 0, 180, '…'),
                premisAgentForUser($pdo, $uid, currentUser($pdo)['name']));
            notifyLibrary($pdo, 'A published work was withdrawn for revision',
                '“' . mb_strimwidth($rec['dc_title'], 0, 70, '…') . '” — ' . mb_strimwidth($reason, 0, 120, '…'),
                'revision', $id);
            logActivity($pdo, $uid, 'withdrew a published work to revise it', $rec['dc_title'], $id);
            flash('ok', 'Taken down for revision. Its link now says it is being revised. Edit it, then send it back to the library.');
        }
    } elseif ($act === 'withdraw' && in_array($rec['status'], ['submitted', 'under_review'], true)) {
        $pdo->prepare("UPDATE records SET status = 'draft' WHERE id = ?")->execute([$id]);
        logActivity($pdo, $uid, 'withdrew a deposit', $rec['dc_title'], $id);
        flash('ok', 'Withdrawn. It is a draft again and no longer in the review queue.');
    } elseif ($act === 'delete' && $rec['status'] === 'draft') {
        $files = $pdo->prepare("SELECT storage_path FROM record_files WHERE record_id = ?");
        $files->execute([$id]);
        foreach ($files->fetchAll() as $f) {
            $p = (string)$f['storage_path'];          // stored absolute at deposit time
            if ($p !== '' && is_file($p)) @unlink($p);
        }
        $pdo->prepare("DELETE FROM records WHERE id = ?")->execute([$id]);
        logActivity($pdo, $uid, 'deleted a draft', $rec['dc_title']);
        flash('ok', 'Draft deleted.');
    } else {
        flash('warn', 'That deposit cannot do that right now.');
    }
    redirect('my-work.php');
}

$filter = (string)($_GET['status'] ?? '');
$where  = ['r.submitted_by = ?'];
$params = [$uid];
if ($filter !== '' && isset(WORKFLOW[$filter])) { $where[] = 'r.status = ?'; $params[] = $filter; }

$stmt = $pdo->prepare(
    "SELECT r.*, d.name AS department_name, a.name AS adviser_name,
            (SELECT COUNT(*) FROM record_files f WHERE f.record_id = r.id) file_count
     FROM records r
     LEFT JOIN departments d ON d.id = r.department_id
     LEFT JOIN users a       ON a.id = r.adviser_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY FIELD(r.status,'revision','draft','submitted','under_review','approved','published','rejected','archived'),
              r.updated_at DESC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$counts = $pdo->prepare("SELECT status, COUNT(*) n FROM records WHERE submitted_by = ? GROUP BY status");
$counts->execute([$uid]);
$byStatus = array_column($counts->fetchAll(), 'n', 'status');
$allCount = array_sum($byStatus);

$reviews = [];
if ($rows) {
    $ids = array_column($rows, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $rv  = $pdo->prepare(
        "SELECT sr.*, u.name AS reviewer FROM submission_reviews sr
         JOIN users u ON u.id = sr.reviewer_id
         WHERE sr.record_id IN ($in) ORDER BY sr.created_at DESC"
    );
    $rv->execute($ids);
    foreach ($rv->fetchAll() as $r) { $reviews[(int)$r['record_id']][] = $r; }
}

$pageLabel = 'My deposits';
$pageSub   = 'Everything you have put into the repository, and where each one stands.';
$topActions = canDeposit()
    ? '<a class="btn btn-accent" href="' . url('submit.php') . '"><i data-ico="upload" class="ico-sm"></i> Deposit work</a>'
    : '';
include ROOT_PATH . '/templates/layout/app_header.php';
?>

<div class="chips mb-6" role="tablist" aria-label="Filter by status">
  <a class="chip <?= $filter === '' ? 'is-active' : '' ?>" href="<?= url('my-work.php') ?>">
    All <span class="facet-count"><?= (int)$allCount ?></span>
  </a>
  <?php foreach (WORKFLOW as $key => $label): if (empty($byStatus[$key])) continue; ?>
  <a class="chip <?= $filter === $key ? 'is-active' : '' ?>" href="<?= url('my-work.php?status=' . $key) ?>">
    <?= e($label) ?> <span class="facet-count"><?= (int)$byStatus[$key] ?></span>
  </a>
  <?php endforeach; ?>
</div>

<?php if (!$rows): ?>
<div class="card">
  <div class="empty">
    <i data-ico="filestack" class="ico-xl"></i>
    <h4><?= $filter ? 'Nothing in that state' : 'You have not deposited anything yet' ?></h4>
    <p class="mt-2" style="max-width:52ch;margin-inline:auto">
      <?= $filter
          ? 'Try another status, or look at everything you have deposited.'
          : (canDeposit()
              ? 'One form is all it takes. The repository mints the identifier, checksums the file with ' . DIGEST_LABEL . ', and builds the Dublin Core, PREMIS and METS metadata as you deposit.'
              : 'Deposits are made by faculty and the library. Ask the adviser who supervised your work to deposit it, naming you as its author.') ?>
    </p>
    <?php if ($filter): ?>
    <a class="btn btn-accent mt-6" href="<?= url('my-work.php') ?>">Show everything</a>
    <?php elseif (canDeposit()): ?>
    <a class="btn btn-accent mt-6" href="<?= url('submit.php') ?>">Deposit your first paper</a>
    <?php else: ?>
    <a class="btn btn-outline mt-6" href="<?= url('about.php') ?>">How deposits work</a>
    <?php endif; ?>
  </div>
</div>
<?php else: ?>

<div class="stack">
<?php foreach ($rows as $r):
  $c        = metadataCompleteness($r);
  $history  = $reviews[(int)$r['id']] ?? [];
  $latest   = $history[0] ?? null;
  $canSend  = in_array($r['status'], ['draft', 'revision'], true);
  $canPull  = in_array($r['status'], ['submitted', 'under_review'], true);
?>
<article class="card">
  <div class="row-between wrap mb-3" style="gap:var(--sp-3)">
    <div class="row wrap" style="gap:8px">
      <?= statusBadge((string)$r['status']) ?>
      <span class="badge badge-brand"><?= e($r['dc_type']) ?></span>
      <?= accessBadge((string)$r['access_level']) ?>
    </div>
    <span class="mono xs subtle"><?= e($r['dc_identifier'] ?: 'Identifier assigned on publication') ?></span>
  </div>

  <h2 style="font-size:var(--fs-lg)">
    <a href="<?= url('record.php?id=' . (int)$r['id']) ?>"><?= e($r['dc_title']) ?></a>
  </h2>

  <div class="result-meta mt-2">
    <span><i data-ico="users" class="ico-sm"></i> <?= e($r['dc_creator']) ?></span>
    <?php if ($r['department_name']): ?><span><i data-ico="cap" class="ico-sm"></i> <?= e($r['department_name']) ?></span><?php endif; ?>
    <span><i data-ico="file" class="ico-sm"></i> <?= (int)$r['file_count'] ?> file<?= (int)$r['file_count'] === 1 ? '' : 's' ?></span>
    <span><i data-ico="clock" class="ico-sm"></i> Updated <?= timeAgo($r['updated_at']) ?></span>
  </div>

  <div class="grid-2 mt-4" style="align-items:start">
    <div>
      <p class="label mb-2">Metadata completeness</p>
      <div class="hbar-row">
        <span class="hbar-track"><span class="hbar-fill" style="width:<?= (int)$c['percent'] ?>%"></span></span>
        <span class="small strong"><?= (int)$c['percent'] ?>%</span>
      </div>
      <p class="xs subtle mt-2">
        <?= (int)$c['required_done'] ?> of <?= (int)$c['required_total'] ?> Dublin Core elements supplied<?php
          if (!empty($c['missing'])) {
              echo '. Still needed: ' . e(implode(', ', array_map('dcElementName', array_slice($c['missing'], 0, 4))));
          } ?>.
      </p>
    </div>
    <div>
      <p class="label mb-2">Where it is</p>
      <?php if ($r['status'] === 'published'): ?>
        <p class="small">Public since <?= humanDate($r['published_at']) ?>. <?= number_format((int)$r['views']) ?> views, <?= number_format((int)$r['downloads']) ?> downloads.</p>
      <?php elseif ($r['status'] === 'approved'): ?>
        <p class="small">Your adviser approved it. The library publishes next.</p>
      <?php elseif ($canPull): ?>
        <p class="small">With <?= e($r['adviser_name'] ?: 'your adviser') ?> since <?= humanDate($r['submitted_at']) ?>.</p>
      <?php elseif ($r['status'] === 'revision'): ?>
        <p class="small">Revisions were requested. Make the changes, then send it back.</p>
      <?php elseif ($r['status'] === 'rejected'): ?>
        <p class="small">Returned without approval. The reviewer's note is below.</p>
      <?php else: ?>
        <p class="small">A draft. Nobody else can see it until you send it for review.</p>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($latest && $latest['comment']): ?>
  <div class="alert alert-<?= $latest['decision'] === 'approved' ? 'ok' : ($latest['decision'] === 'rejected' ? 'danger' : 'warn') ?> mt-4">
    <i data-ico="quote" class="ico-sm"></i>
    <span>
      <span class="strong"><?= e($latest['reviewer']) ?></span>
      <span class="subtle">· <?= e(ucfirst($latest['stage'])) ?> review, <?= timeAgo($latest['created_at']) ?></span><br>
      <?= e($latest['comment']) ?>
    </span>
  </div>
  <?php endif; ?>

  <div class="row wrap mt-4" style="gap:8px">
    <a class="btn btn-outline btn-sm" href="<?= url('record.php?id=' . (int)$r['id']) ?>">Open record</a>
    <?php if (canDeposit() && canEditRecord($r)): ?>
    <a class="btn btn-primary btn-sm" href="<?= url('submit.php?id=' . (int)$r['id']) ?>"><i data-ico="edit" class="ico-sm"></i> Edit<?= $r['status'] === 'revision' ? ' and resend' : '' ?></a>
    <?php endif; ?>

    <?php if ($r['status'] === 'published' && canDeposit()): ?>
    <details class="inline-act">
      <summary class="btn btn-outline btn-sm"><i data-ico="edit" class="ico-sm"></i> Withdraw to revise</summary>
      <form method="post" class="inline-form">
        <?= csrfField() ?>
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <input type="hidden" name="action" value="unpublish">
        <label class="label" for="why<?= (int)$r['id'] ?>">What are you revising?</label>
        <textarea class="textarea" id="why<?= (int)$r['id'] ?>" name="reason" rows="3" required
                  placeholder="e.g. Adding the panel's corrections to Chapter 4"></textarea>
        <p class="hint">It leaves the public collection until the library publishes it again, under the same identifier.</p>
        <button class="btn btn-primary btn-sm mt-2" type="submit">Take it down to revise</button>
      </form>
    </details>
    <?php endif; ?>

    <?php if ($canSend): ?>
    <form method="post" style="display:contents">
      <?= csrfField() ?>
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <input type="hidden" name="action" value="send">
      <button class="btn btn-primary btn-sm" type="submit"><i data-ico="arrowright" class="ico-sm"></i> Send for review</button>
    </form>
    <?php endif; ?>

    <?php if ($canPull): ?>
    <form method="post" style="display:contents">
      <?= csrfField() ?>
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <input type="hidden" name="action" value="withdraw">
      <button class="btn btn-ghost btn-sm" type="submit">Withdraw</button>
    </form>
    <?php endif; ?>

    <?php if ($r['status'] === 'draft'): ?>
    <form method="post" style="display:contents"
          onsubmit="return confirm('Delete this draft and its uploaded file? This cannot be undone.')">
      <?= csrfField() ?>
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <input type="hidden" name="action" value="delete">
      <button class="btn btn-ghost btn-sm" type="submit" style="color:var(--danger)"><i data-ico="trash" class="ico-sm"></i> Delete draft</button>
    </form>
    <?php endif; ?>
  </div>
</article>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php include ROOT_PATH . '/templates/layout/app_footer.php'; ?>
