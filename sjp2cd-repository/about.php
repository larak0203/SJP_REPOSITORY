<?php
/* ============================================================================
   About the repository.

   This page carries what used to sit on the home page: the deposit policy, the
   three standards, and the capstone framing behind the system. It is here, one
   click from every page, rather than in front of a reader who came to find a
   thesis. That split is deliberate -- the home page serves the college, this
   page answers the person who wants to know how the collection is run.
   ============================================================================ */

require_once __DIR__ . '/config/config.php';
require_once ROOT_PATH . '/includes/metadata.php';

/* Counted live, so the page describes the system rather than claiming things
   about it. */
$n = $pdo->query(
    "SELECT
      (SELECT COUNT(*) FROM records WHERE status='published')          published,
      (SELECT COUNT(*) FROM records WHERE status='archived')           archived,
      (SELECT COUNT(*) FROM departments WHERE is_active=1)             programmes,
      (SELECT COUNT(*) FROM premis_events)                             events,
      (SELECT COUNT(DISTINCT submitted_by) FROM records
        WHERE status IN ('published','archived'))                      depositors"
)->fetch();

$pageTitle = 'About the repository';
$navActive = 'about';
include ROOT_PATH . '/templates/layout/header.php';
?>

<main id="main">

<section class="section-sm">
  <div class="container">
    <nav aria-label="Breadcrumb" class="mb-4">
      <ol class="row small subtle" style="gap:8px">
        <li><a href="<?= url('index.php') ?>">Home</a></li>
        <li aria-hidden="true">/</li>
        <li aria-current="page">About</li>
      </ol>
    </nav>

    <div class="container-narrow" style="margin-inline:0">
      <h1 style="font-size:var(--fs-3xl)">About this repository</h1>
      <p class="hero-lede mt-4">
        The institutional repository of St. John Paul II College of Davao collects the
        finished research of the college &mdash; theses, capstone projects and faculty
        work &mdash; and keeps it findable and readable for the long term.
      </p>
    </div>

    <div class="stat-row mt-8">
      <article data-tilt class="stat stat-blue">
        <span class="stat-ico"><i data-ico="book"></i></span>
        <span class="stat-value"><?= number_format((int)$n['published']) ?></span>
        <span class="stat-label">Works published</span>
        <span class="stat-foot"><?= number_format((int)$n['archived']) ?> more in the archive</span>
      </article>
      <article data-tilt class="stat stat-mint">
        <span class="stat-ico"><i data-ico="building"></i></span>
        <span class="stat-value"><?= (int)$n['programmes'] ?></span>
        <span class="stat-label">Programmes represented</span>
        <span class="stat-foot">Every active college programme</span>
      </article>
      <article data-tilt class="stat stat-gold">
        <span class="stat-ico"><i data-ico="users"></i></span>
        <span class="stat-value"><?= number_format((int)$n['depositors']) ?></span>
        <span class="stat-label">Depositors</span>
        <span class="stat-foot">Faculty and library staff</span>
      </article>
      <article data-tilt class="stat stat-lilac">
        <span class="stat-ico"><i data-ico="history"></i></span>
        <span class="stat-value"><?= number_format((int)$n['events']) ?></span>
        <span class="stat-label">Preservation events</span>
        <span class="stat-foot">Logged against every deposit</span>
      </article>
    </div>
  </div>
</section>

<!-- ============================ DEPOSIT POLICY ============================ -->
<section class="section-alt section-sm">
  <div class="container">
    <div class="section-head">
      <h2 class="section-title">Who may deposit</h2>
      <p class="section-lede">
        Deposit is mediated. Faculty deposit their own research, and deposit the student
        work they supervised; the library reviews everything before it is published.
      </p>
    </div>

    <div class="grid-2 mt-6">
      <article data-tilt class="card reveal">
        <h4>Visitors</h4>
        <p>Anyone may register with any email address and search the whole catalogue, reading
           every description, abstract and keyword.</p>
        <p class="small muted mt-3">Downloading a file needs a college account, which is one
           held on a <code><?= e(institutionalDomainList()) ?></code> address.</p>
      </article>

      <article data-tilt class="card reveal">
        <h4>Students</h4>
        <p>Search, read and download. A student account is what opens a full text that is
           not public. Students do not deposit directly.</p>
        <p class="small muted mt-3">To get your thesis into the repository, ask the
           adviser who supervised it to deposit it. You are recorded as its author.</p>
      </article>

      <article data-tilt class="card reveal" data-delay="90">
        <h4>Faculty</h4>
        <p>Deposit your own research, and the student work you supervised. The author of
           the work is named as the author &mdash; not the person filing it.</p>
        <p class="small muted mt-3">Deposits go to the library for review before they
           appear in the public collection.</p>
      </article>

      <article data-tilt class="card reveal" data-delay="180">
        <h4>The library</h4>
        <p>Reviews every deposit, publishes it, mints its citable identifier, and runs the
           integrity checks that keep the stored files honest.</p>
        <p class="small muted mt-3">Questions about access, corrections or withdrawals go
           to <code>repository@sjp2cd.edu.ph</code>.</p>
      </article>
    </div>
  </div>
</section>

<!-- ========================= PRESERVATION POLICY ========================== -->
<!-- A repository that says it preserves work should say how, in writing, where
     anyone can read it. This is the policy the system is built to carry out. -->
<section class="section-sm">
  <div class="container">
    <div class="container-narrow" style="margin-inline:0">
      <h2 class="section-title">How the collection is looked after</h2>

      <h4 class="mt-6">What is kept, and for how long</h4>
      <p class="mt-2">Every published work is kept permanently. Work completed more than
         <?= ARCHIVE_AFTER_YEARS ?> years ago moves automatically to the archive: it leaves the
         main catalogue but keeps its identifier, description and files, and stays searchable,
         readable and citable.</p>

      <h4 class="mt-6">In what form</h4>
      <p class="mt-2">Manuscripts are accepted as PDF only, because a PDF keeps its layout
         and stays readable far longer than a word-processor file. The repository records each
         file's format and flags formats at risk, but it does not convert files.</p>

      <h4 class="mt-6">How we know nothing has changed</h4>
      <p class="mt-2">Every file is fingerprinted with <?= DIGEST_LABEL ?> when it is deposited.
         Scheduled integrity checks recompute that fingerprint and compare the two; any
         difference is reported to the library.</p>

      <h4 class="mt-6">Copies</h4>
      <p class="mt-2">The database and every file are copied to a second location on a
         schedule, and each copy is checked against its fingerprint before it counts as a
         backup.</p>

      <h4 class="mt-6">Changes and removal</h4>
      <p class="mt-2">A published work can be withdrawn for a time so its author can revise
         it; it returns under the same identifier, and earlier file versions are kept. Published
         work cannot be deleted. If a work has to leave public view, for example after a
         copyright claim, the library withdraws it and the withdrawal is recorded in its
         preservation history.</p>

      <h4 class="mt-6">Who is responsible</h4>
      <p class="mt-2">The College Library carries out this policy. Every step it takes is
         recorded as a PREMIS preservation event against the work concerned.</p>

      <p class="small muted mt-6">This repository follows the OAIS reference model (ISO 14721)
         for ingest, archival storage, data management and access. It is not certified as an
         OAIS-compliant archive.</p>
    </div>
  </div>
</section>

<!-- ========================= THE STUDY BEHIND IT ========================== -->
<section class="section-sm">
  <div class="container">
    <div class="section-head">
      <h2 class="section-title">The study behind the system</h2>
      <p class="section-lede">
        This repository is the working implementation of a capstone study in the College of
        Information and Communications Technology. It set out to do three things, and each
        one is something you can open and use.
      </p>
    </div>

    <div class="grid-3 mt-6">
      <article data-tilt class="card obj reveal">
        <div class="obj-num">1</div>
        <div>
          <h4>Deposit, store and manage</h4>
          <p>One place for theses, capstone projects and research papers, with a review path
             from depositor to library &mdash; replacing shared drives and printed copies.</p>
          <a class="btn btn-ghost btn-sm mt-4" style="padding-inline:0" href="<?= url('browse.php') ?>">
            See the collection <i data-ico="arrowright" class="ico-sm"></i>
          </a>
        </div>
      </article>

      <article data-tilt class="card obj reveal" data-delay="90">
        <div class="obj-num">2</div>
        <div>
          <h4>Describe and preserve properly</h4>
          <p>Dublin Core so a record can be found, PREMIS so it can be trusted, METS so the
             whole package holds together &mdash; written for every deposit.</p>
          <a class="btn btn-ghost btn-sm mt-4" style="padding-inline:0" href="<?= url('standards.php') ?>">
            How work is described <i data-ico="arrowright" class="ico-sm"></i>
          </a>
        </div>
      </article>

      <article data-tilt class="card obj reveal" data-delay="180">
        <div class="obj-num">3</div>
        <div>
          <h4>Make depositing worth doing</h4>
          <p>A form that explains itself, shows what is still missing, and never asks anyone
             to touch an XML schema &mdash; so faculty actually archive their own work.</p>
          <a class="btn btn-ghost btn-sm mt-4" style="padding-inline:0" href="<?= url('preservation.php') ?>">
            How it is preserved <i data-ico="arrowright" class="ico-sm"></i>
          </a>
        </div>
      </article>
    </div>
  </div>
</section>

<section class="section-alt section-sm">
  <div class="container">
    <div class="container-narrow" style="margin-inline:0">
      <h2 class="section-title">Getting in touch</h2>
      <p class="mt-3 muted">
        The College Library runs this repository. Write to <code>repository@sjp2cd.edu.ph</code>
        about access to a restricted work, a correction to a record, or a withdrawal request.
      </p>
      <div class="row wrap mt-6" style="gap:var(--sp-3)">
        <a class="btn btn-primary" href="<?= url('browse.php') ?>">Browse the collection</a>
        <a class="btn btn-outline" href="<?= url('standards.php') ?>">How work is described</a>
        <a class="btn btn-outline" href="<?= url('preservation.php') ?>">How it is preserved</a>
      </div>
    </div>
  </div>
</section>

</main>
<?php include ROOT_PATH . '/templates/layout/footer.php'; ?>
