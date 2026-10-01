<?php
require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/includes/metadata.php';
requireRole('admin');

/* ------------------------------------------------------------ actions --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $id  = (int)($_POST['id'] ?? 0);
    $act = (string)($_POST['action'] ?? '');

    $s = $pdo->prepare("SELECT * FROM records WHERE id = ?");
    $s->execute([$id]);
    $rec = $s->fetch();

    if (!$rec) {
        flash('danger', 'That record no longer exists.');
        redirect('manage-records.php');
    }

    $agent = premisAgentForUser($pdo, currentUserId(), (string)currentUser($pdo)['name']);

    switch ($act) {
        case 'archive':
            $pdo->prepare("UPDATE records SET status='archived', archived_at=NOW() WHERE id=?")->execute([$id]);
            premisEvent($pdo, $id, 'deaccession', 'success', 'Withdrawn from the public catalogue', $agent);
            notify($pdo, (int)$rec['submitted_by'], 'Your record was archived',
                   $rec['dc_title'] . ' is no longer listed publicly.', 'archived', $id);
            logActivity($pdo, currentUserId(), 'archived a record', $rec['dc_title'], $id);
            flash('ok', 'Archived. It keeps its identifier and metadata but no longer appears in Browse.');
            break;

        case 'restore':
            $pdo->prepare("UPDATE records SET status='published', archived_at=NULL WHERE id=?")->execute([$id]);
            premisEvent($pdo, $id, 'publication', 'success', 'Restored to the public catalogue', $agent);
            logActivity($pdo, currentUserId(), 'restored a record', $rec['dc_title'], $id);
            flash('ok', 'Back in the public catalogue.');
            break;

        case 'access':
            $level = (string)($_POST['access_level'] ?? '');
            if (!isset(ACCESS_LEVELS[$level])) { flash('danger', 'Unknown access level.'); break; }
            $pdo->prepare("UPDATE records SET access_level=?, dc_rights=? WHERE id=?")
                ->execute([$level, rightsStatementFor($level), $id]);
            $pdo->prepare("UPDATE premis_rights SET rights_statement=? WHERE record_id=?")
                ->execute([rightsStatementFor($level), $id]);
            premisEvent($pdo, $id, 'policy assignment', 'success',
                'Access level set to ' . $level, $agent);
            logActivity($pdo, currentUserId(), 'changed access level', $rec['dc_title'] . ' → ' . $level, $id);
            flash('ok', 'Access level updated to <strong>' . e($level) . '</strong>.');
            break;

        case 'publish':
            if (!in_array($rec['status'], ['approved', 'submitted', 'under_review'], true)) {
                flash('warn', 'Only a submission awaiting publication can be published.'); break;
            }
            $identifier = $rec['dc_identifier'] ?: mintIdentifier($pdo);
            $pdo->prepare(
                "UPDATE records
                    SET status = 'published', published_at = NOW(), published_by = ?, dc_identifier = ?,
                        dc_date_issued = COALESCE(dc_date_issued, CURDATE())
                  WHERE id = ?"
            )->execute([currentUserId(), $identifier, $id]);
            $pdo->prepare(
                "INSERT INTO submission_reviews (record_id, reviewer_id, stage, decision, comment)
                 VALUES (?, ?, 'library', 'approved', 'Released to the public catalogue.')"
            )->execute([$id, currentUserId()]);
            premisEvent($pdo, $id, 'publication', 'success',
                'Released to the public catalogue as ' . $identifier, $agent);
            buildMetsPackage($pdo, $id);
            notify($pdo, (int)$rec['submitted_by'], 'Your deposit is published',
                   $rec['dc_title'] . ' is now public as ' . $identifier, 'published', $id);
            logActivity($pdo, currentUserId(), 'published a record', $rec['dc_title'], $id);
            flash('ok', 'Published as <strong>' . e($identifier) . '</strong>.');
            break;

        case 'revision':
            $note = trim((string)($_POST['comment'] ?? ''));
            if ($note === '') { flash('warn', 'Say what needs changing before sending it back.'); break; }
            $pdo->prepare("UPDATE records SET status = 'revision' WHERE id = ?")->execute([$id]);
            $pdo->prepare(
                "INSERT INTO submission_reviews (record_id, reviewer_id, stage, decision, comment)
                 VALUES (?, ?, 'library', 'revision', ?)"
            )->execute([$id, currentUserId(), $note]);
            premisEvent($pdo, $id, $rec['status'] === 'published' ? 'withdrawal' : 'validation',
                $rec['status'] === 'published' ? 'success' : 'fail',
                ($rec['status'] === 'published' ? 'Temporarily withdrawn by the library for revision: ' : 'Revisions requested: ')
                . mb_strimwidth($note, 0, 180, '…'), $agent);
            notify($pdo, (int)$rec['submitted_by'], 'Revisions were requested',
                   mb_strimwidth($note, 0, 180, '…'), 'revision', $id);
            logActivity($pdo, currentUserId(), 'requested revisions', $rec['dc_title'], $id);
            flash('ok', 'Sent back for revision.');
            break;

        case 'reject':
            $note = trim((string)($_POST['comment'] ?? ''));
            if ($note === '') { flash('warn', 'Give a reason before returning the work.'); break; }
            $pdo->prepare("UPDATE records SET status = 'rejected' WHERE id = ?")->execute([$id]);
            $pdo->prepare(
                "INSERT INTO submission_reviews (record_id, reviewer_id, stage, decision, comment)
                 VALUES (?, ?, 'library', 'rejected', ?)"
            )->execute([$id, currentUserId(), $note]);
            premisEvent($pdo, $id, 'validation', 'fail', 'Returned without approval', $agent);
            notify($pdo, (int)$rec['submitted_by'], 'Your deposit was returned',
                   mb_strimwidth($note, 0, 180, '…'), 'rejected', $id);
            logActivity($pdo, currentUserId(), 'returned a submission', $rec['dc_title'], $id);
            flash('ok', 'Returned to the depositor.');
            break;

        case 'delete':
            /* Deletion is permanent and removes the files too, so it is limited to
               records that were never published. A published record is archived
               instead — the identifier has been issued and must keep resolving. */
            if (in_array($rec['status'], ['published', 'archived'], true)) {
                flash('danger', 'A published record cannot be deleted. Archive it instead — its identifier has already been issued.');
                break;
            }
            $files = $pdo->prepare("SELECT storage_path FROM record_files WHERE record_id = ?");
            $files->execute([$id]);
            foreach ($files->fetchAll() as $f) {
                $path = (string)$f['storage_path'];
                if ($path !== '' && is_file($path)) @unlink($path);
            }
            $pdo->prepare("DELETE FROM records WHERE id = ?")->execute([$id]);
            logActivity($pdo, currentUserId(), 'deleted a record', $rec['dc_title']);
            flash('ok', 'Deleted, along with its files.');
            break;

        case 'rebuild':
            buildMetsPackage($pdo, $id);
            premisEvent($pdo, $id, 'metadata modification', 'success', 'METS package regenerated', $agent);
            logActivity($pdo, currentUserId(), 'rebuilt a METS package', $rec['dc_title'], $id);
            flash('ok', 'METS package rebuilt from the current metadata.');
            break;

        default:
            flash('warn', 'Nothing to do.');
    }
    redirect('manage-records.php' . (($_POST['back'] ?? '') ? '?' . $_POST['back'] : ''));
}

/* ------------------------------------------------------------- filters --- */
$q      = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 20;

$counts = $pdo->query("SELECT status, COUNT(*) n FROM records GROUP BY status")->fetchAll();
$byStatus = array_column($counts, 'n', 'status');
$grand = array_sum($byStatus);

/* Figure 6 grouped the eight workflow states into four outcomes. Two of those
   groups hid what a librarian most needs to see apart: "Approved" mixed live
   work with archived work, and "Rejected" mixed work that is finished with
   work that is only waiting on the author's changes -- including published
   work withdrawn to be revised. Each now has its own tab. */
$TABS = [
    ''          => ['All',            $grand],
    'pending'   => ['Pending',        ($byStatus['submitted'] ?? 0) + ($byStatus['under_review'] ?? 0) + ($byStatus['approved'] ?? 0)],
    'revision'  => ['Needs revision', ($byStatus['revision'] ?? 0)],
    'published' => ['Published',      ($byStatus['published'] ?? 0)],
    'archived'  => ['Archived',       ($byStatus['archived'] ?? 0)],
    'rejected'  => ['Returned',       ($byStatus['rejected'] ?? 0)],
];
$tab = (string)($_GET['tab'] ?? '');
if (!array_key_exists($tab, $TABS)) $tab = '';

$where = ['1=1']; $params = [];
if ($q !== '') {
    $where[] = "(r.dc_title LIKE ? OR r.dc_creator LIKE ? OR r.dc_identifier LIKE ?)";
    array_push($params, "%$q%", "%$q%", "%$q%");
}
if ($status !== '' && isset(WORKFLOW[$status])) { $where[] = "r.status = ?"; $params[] = $status; }
if ($tab === 'pending')   { $where[] = "r.status IN ('submitted','under_review','approved')"; }
if ($tab === 'revision')  { $where[] = "r.status = 'revision'"; }
if ($tab === 'published') { $where[] = "r.status = 'published'"; }
if ($tab === 'archived')  { $where[] = "r.status = 'archived'"; }
if ($tab === 'rejected')  { $where[] = "r.status = 'rejected'"; }
$sql = implode(' AND ', $where);

$cnt = $pdo->prepare("SELECT COUNT(*) FROM records r WHERE $sql");
$cnt->execute($params);
$total  = (int)$cnt->fetchColumn();
$pages  = max(1, (int)ceil($total / $per));
$page   = min($page, $pages);
$offset = ($page - 1) * $per;

$rows = $pdo->prepare(
    "SELECT r.*, u.name AS author, d.name AS department_name,
            (SELECT COUNT(*) FROM record_files f WHERE f.record_id = r.id) file_count,
            (SELECT COUNT(*) FROM premis_events e WHERE e.record_id = r.id) event_count,
            (SELECT COUNT(*) FROM mets_packages p WHERE p.record_id = r.id) has_mets
     FROM records r
     JOIN users u ON u.id = r.submitted_by
     LEFT JOIN departments d ON d.id = r.department_id
     WHERE $sql ORDER BY r.updated_at DESC LIMIT $per OFFSET $offset"
);
$rows->execute($params);
$records = $rows->fetchAll();


$backQuery = http_build_query(array_filter(['q' => $q, 'status' => $status, 'tab' => $tab, 'page' => $page > 1 ? $page : '']));

$pageLabel = 'All records';
$pageSub   = 'Every deposit in the repository, whatever its state.';
include ROOT_PATH . '/templates/layout/app_header.php';
?>

<!-- Figure 6: All / Pending / Approved / Rejected, with live counts. -->
<div class="chips mb-4" role="tablist" aria-label="Filter submissions by outcome">
  <?php foreach ($TABS as $key => [$label, $n]): ?>
  <a class="chip <?= $tab === $key ? 'is-active' : '' ?>"
     href="<?= url('manage-records.php' . ($key ? '?tab=' . $key : '')) ?>"
     <?= $tab === $key ? 'aria-current="page"' : '' ?>>
    <?= e($label) ?> <span class="facet-count"><?= (int)$n ?></span>
  </a>
  <?php endforeach; ?>
</div>

<form class="card card-tight mb-6" method="get" role="search">
  <?php if ($tab !== ''): ?><input type="hidden" name="tab" value="<?= e($tab) ?>"><?php endif; ?>
  <div class="row wrap" style="gap:var(--sp-3)">
    <div class="input-icon grow" style="min-width:240px">
      <i data-ico="search"></i>
      <label class="sr-only" for="q">Search records</label>
      <input class="input" id="q" name="q" type="search" value="<?= e($q) ?>"
             placeholder="Title, author or identifier" autocomplete="off">
    </div>
    <label class="sr-only" for="status">Status</label>
    <select class="select" id="status" name="status" style="width:auto;min-width:190px" onchange="this.form.submit()">
      <option value="">Every status (<?= (int)$grand ?>)</option>
      <?php foreach (WORKFLOW as $k => $label): ?>
      <option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>>
        <?= e($label) ?><?= isset($byStatus[$k]) ? ' (' . (int)$byStatus[$k] . ')' : '' ?>
      </option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-primary" type="submit">Filter</button>
    <?php if ($q !== '' || $status !== ''): ?>
    <a class="btn btn-ghost" href="<?= url('manage-records.php' . ($tab ? '?tab=' . $tab : '')) ?>">Clear</a>
    <?php endif; ?>
  </div>
</form>

<p class="small subtle mb-4" role="status" aria-live="polite">
  <?= number_format($total) ?> record<?= $total === 1 ? '' : 's' ?><?= $q !== '' ? ' matching “' . e($q) . '”' : '' ?><?php
    if ($total) echo ', showing ' . number_format($offset + 1) . '–' . number_format(min($offset + $per, $total)); ?>.
</p>

<?php if (!$records): ?>
<div class="card"><div class="empty">
  <i data-ico="archive" class="ico-xl"></i>
  <h4>No records match</h4>
  <p class="mt-2">Change the filter, or clear it to see everything.</p>
</div></div>
<?php else: ?>

<div class="stack">
<?php foreach ($records as $r): ?>
<article class="card">
  <div class="row-between wrap mb-3" style="gap:var(--sp-3)">
    <div class="row wrap" style="gap:8px">
      <?= statusBadge((string)$r['status']) ?>
      <span class="badge badge-brand"><?= e($r['dc_type']) ?></span>
      <?= accessBadge((string)$r['access_level']) ?>
      <?php if (!(int)$r['has_mets']): ?>
      <span class="badge badge-warn"><i data-ico="alert" class="ico-sm"></i> No METS package</span>
      <?php endif; ?>
    </div>
    <span class="mono xs subtle"><?= e($r['dc_identifier'] ?: '—') ?></span>
  </div>

  <h2 style="font-size:var(--fs-lg)">
    <a href="<?= url('record.php?id=' . (int)$r['id']) ?>"><?= e($r['dc_title']) ?></a>
  </h2>

  <div class="result-meta mt-2">
    <span><i data-ico="user" class="ico-sm"></i> <?= e($r['author']) ?></span>
    <?php if ($r['department_name']): ?><span><i data-ico="cap" class="ico-sm"></i> <?= e($r['department_name']) ?></span><?php endif; ?>
    <span><i data-ico="file" class="ico-sm"></i> <?= (int)$r['file_count'] ?></span>
    <span><i data-ico="history" class="ico-sm"></i> <?= (int)$r['event_count'] ?> events</span>
    <span><i data-ico="eye" class="ico-sm"></i> <?= number_format((int)$r['views']) ?></span>
    <span><i data-ico="download" class="ico-sm"></i> <?= number_format((int)$r['downloads']) ?></span>
    <span><i data-ico="clock" class="ico-sm"></i> <?= timeAgo($r['updated_at']) ?></span>
  </div>

  <div class="row wrap mt-4" style="gap:8px;align-items:flex-end">
    <a class="btn btn-outline btn-sm" href="<?= url('record.php?id=' . (int)$r['id']) ?>">Open</a>
    <a class="btn btn-ghost btn-sm" href="<?= url('mets.php?id=' . (int)$r['id']) ?>" target="_blank" rel="noopener">
      <i data-ico="layers" class="ico-sm"></i> METS
    </a>

    <form method="post" class="row" style="gap:6px;align-items:flex-end">
      <?= csrfField() ?>
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <input type="hidden" name="back" value="<?= e($backQuery) ?>">
      <input type="hidden" name="action" value="access">
      <span>
        <label class="sr-only" for="acc<?= (int)$r['id'] ?>">Access level for <?= e($r['dc_title']) ?></label>
        <select class="select" id="acc<?= (int)$r['id'] ?>" name="access_level" style="width:auto;min-width:150px">
          <?php foreach (ACCESS_LEVELS as $k => $desc): ?>
          <option value="<?= $k ?>" <?= $r['access_level'] === $k ? 'selected' : '' ?>><?= e(ucfirst($k)) ?></option>
          <?php endforeach; ?>
        </select>
      </span>
      <button class="btn btn-ghost btn-sm" type="submit">Set access</button>
    </form>

    <form method="post" style="display:contents">
      <?= csrfField() ?>
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <input type="hidden" name="back" value="<?= e($backQuery) ?>">
      <input type="hidden" name="action" value="rebuild">
      <button class="btn btn-ghost btn-sm" type="submit"><i data-ico="refresh" class="ico-sm"></i> Rebuild METS</button>
    </form>

    <?php
      /* Every action carries the same hidden fields, so they are written once. */
      $hidden = csrfField()
              . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
              . '<input type="hidden" name="back" value="' . e($backQuery) . '">';
    ?>

    <?php if (in_array($r['status'], ['approved', 'submitted', 'under_review'], true)): ?>
    <form method="post" style="display:contents">
      <?= $hidden ?><input type="hidden" name="action" value="publish">
      <button class="btn btn-primary btn-sm" type="submit"><i data-ico="check" class="ico-sm"></i> Publish</button>
    </form>
    <details class="inline-act">
      <summary class="btn btn-outline btn-sm"><i data-ico="edit" class="ico-sm"></i> Request revision</summary>
      <form method="post" class="inline-form">
        <?= $hidden ?><input type="hidden" name="action" value="revision">
        <label class="sr-only" for="rev<?= (int)$r['id'] ?>">What needs changing</label>
        <textarea class="textarea" id="rev<?= (int)$r['id'] ?>" name="comment" rows="3" required
                  placeholder="What does the depositor need to change?"></textarea>
        <button class="btn btn-primary btn-sm mt-2" type="submit">Send back</button>
      </form>
    </details>
    <details class="inline-act">
      <summary class="btn btn-outline btn-sm"><i data-ico="xcircle" class="ico-sm"></i> Return</summary>
      <form method="post" class="inline-form">
        <?= $hidden ?><input type="hidden" name="action" value="reject">
        <label class="sr-only" for="rej<?= (int)$r['id'] ?>">Reason for returning</label>
        <textarea class="textarea" id="rej<?= (int)$r['id'] ?>" name="comment" rows="3" required
                  placeholder="Why is this being returned?"></textarea>
        <button class="btn btn-danger btn-sm mt-2" type="submit">Return it</button>
      </form>
    </details>
    <?php endif; ?>

    <a class="btn btn-ghost btn-sm" href="<?= url('submit.php?id=' . (int)$r['id']) ?>"><i data-ico="edit" class="ico-sm"></i> Edit</a>

    <?php if ($r['status'] === 'published'): ?>
    <details class="inline-act">
      <summary class="btn btn-outline btn-sm"><i data-ico="edit" class="ico-sm"></i> Withdraw for revision</summary>
      <form method="post" class="inline-form">
        <?= $hidden ?><input type="hidden" name="action" value="revision">
        <label class="sr-only" for="wd<?= (int)$r['id'] ?>">What needs revising</label>
        <textarea class="textarea" id="wd<?= (int)$r['id'] ?>" name="comment" rows="3" required
                  placeholder="What needs revising? The depositor sees this."></textarea>
        <button class="btn btn-primary btn-sm mt-2" type="submit">Take it down</button>
      </form>
    </details>
    <?php endif; ?>

    <?php if ($r['status'] === 'archived'): ?>
    <form method="post" style="display:contents">
      <?= $hidden ?><input type="hidden" name="action" value="restore">
      <button class="btn btn-primary btn-sm" type="submit">Restore to catalogue</button>
    </form>
    <?php elseif ($r['status'] === 'published'): ?>
    <form method="post" style="display:contents"
          onsubmit="return confirm('Archive this record? It keeps its identifier and metadata but leaves the public catalogue.')">
      <?= $hidden ?><input type="hidden" name="action" value="archive">
      <button class="btn btn-ghost btn-sm" type="submit">Archive</button>
    </form>
    <?php endif; ?>

    <?php if (!in_array($r['status'], ['published', 'archived'], true)): ?>
    <!-- Deletion is offered only where no identifier has been issued. A published
         record is archived instead, so its identifier keeps resolving. -->
    <form method="post" style="display:contents"
          onsubmit="return confirm('Delete this record and its files permanently? This cannot be undone.')">
      <?= $hidden ?><input type="hidden" name="action" value="delete">
      <button class="btn btn-ghost btn-sm" type="submit" style="color:var(--danger)"><i data-ico="trash" class="ico-sm"></i> Delete</button>
    </form>
    <?php endif; ?>
  </div>
</article>
<?php endforeach; ?>
</div>

<?php if ($pages > 1): ?>
<nav class="row-between mt-6 wrap" aria-label="Pages" style="gap:var(--sp-3)">
  <?php $qs = static fn(int $p): string => url('manage-records.php?' . http_build_query(array_filter(['q' => $q, 'status' => $status, 'page' => $p]))); ?>
  <?php if ($page > 1): ?><a class="btn btn-outline btn-sm" href="<?= $qs($page - 1) ?>"><i data-ico="chevleft" class="ico-sm"></i> Previous</a><?php else: ?><span></span><?php endif; ?>
  <p class="small subtle">Page <?= $page ?> of <?= $pages ?></p>
  <?php if ($page < $pages): ?><a class="btn btn-outline btn-sm" href="<?= $qs($page + 1) ?>">Next <i data-ico="chevright" class="ico-sm"></i></a><?php else: ?><span></span><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>

<?php include ROOT_PATH . '/templates/layout/app_footer.php'; ?>
