<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">
      <div>
        <a class="brand" href="<?= url('index.php') ?>">
          <span class="brand-logo"><img src="<?= url('assets/img/logo.svg') ?>" alt=""></span>
          <span>
            <span class="brand-name">SJP2CD Repository</span><br>
            <span class="brand-sub">St. John Paul II College of Davao</span>
          </span>
        </a>
        <p class="small mt-3" style="color:var(--gold-300);font-style:italic">Ad Astra Per Aspera</p>
        <p class="small mt-3" style="color:var(--navy-200);max-width:42ch">
          The institutional repository for theses, capstone projects and research
          of St. John Paul II College of Davao.
        </p>
        <div class="row mt-6" style="gap:var(--sp-4)">
          <span class="badge badge-gold"><i data-ico="database"></i> Dublin Core</span>
          <span class="badge badge-gold"><i data-ico="shield"></i> PREMIS</span>
          <span class="badge badge-gold"><i data-ico="layers"></i> METS</span>
        </div>
      </div>

      <div>
        <h5>Find</h5>
        <ul>
          <li><a href="<?= url('browse.php') ?>">Browse everything</a></li>
          <li><a href="<?= url('browse.php?type=Thesis') ?>">Theses</a></li>
          <li><a href="<?= url('browse.php?type=Capstone+Project') ?>">Capstone projects</a></li>
          <li><a href="<?= url('browse.php?type=Research+Paper') ?>">Research papers</a></li>
        </ul>
      </div>

      <div>
        <h5>About</h5>
        <ul>
          <li><a href="<?= url('about.php') ?>">About the repository</a></li>
          <li><a href="<?= url('standards.php') ?>">How work is described</a></li>
          <li><a href="<?= url('preservation.php') ?>">How it is preserved</a></li>
          <?php if (canDeposit()): ?>
          <li><a href="<?= url('submit.php') ?>">Deposit your work</a></li>
          <?php elseif (!isLoggedIn()): ?>
          <li><a href="<?= url('register.php') ?>">Create an account</a></li>
          <?php endif; ?>
        </ul>
      </div>

      <div>
        <h5>Ask</h5>
        <ul>
          <li class="small" style="color:var(--navy-200);display:flex;gap:8px;align-items:flex-start;padding:5px 0">
            <i data-ico="mail" class="ico-sm"></i> <span>repository@sjp2cd.edu.ph</span>
          </li>
          <li class="small" style="color:var(--navy-200);display:flex;gap:8px;align-items:center;padding:5px 0">
            <i data-ico="clock" class="ico-sm"></i> <span>Library desk, Mon–Sat 8am–5pm</span>
          </li>
          <li class="small" style="color:var(--navy-200);display:flex;gap:8px;align-items:flex-start;padding:5px 0">
            <i data-ico="pin" class="ico-sm"></i> <span>Davao City, Philippines</span>
          </li>
        </ul>
      </div>
    </div>

    <div class="footer-bot">
      <span>© <?= date('Y') ?> St. John Paul II College of Davao</span>
      <span>Descriptive metadata follows Dublin Core; preservation follows PREMIS 3.0; packaging follows METS 1.12.</span>
    </div>
  </div>
</footer>

<div class="toasts" aria-live="polite"></div>

<script src="<?= url('assets/js/icons.js') ?>?v=<?= @filemtime(ROOT_PATH . '/assets/js/icons.js') ?: time() ?>"></script>
<script src="<?= url('assets/js/ui.js') ?>?v=<?= @filemtime(ROOT_PATH . '/assets/js/ui.js') ?: time() ?>"></script>

<!-- ===================== Repository assistant =====================
     Answers from this database and a curated knowledge base. No language
     model, so it cannot invent a feature the system does not have. -->
<div class="asst" data-endpoint="<?= e(url('assistant.php')) ?>">
  <button class="asst-fab" type="button" data-asst-toggle
          aria-expanded="false" aria-controls="asst-panel">
    <span class="asst-fab-ico" aria-hidden="true"><i data-ico="sparkle"></i></span>
    <span class="asst-fab-label">Ask about the repository</span>
  </button>
  <span class="asst-grip" aria-hidden="true"></span>

  <div class="asst-panel" id="asst-panel" role="dialog" aria-label="Repository assistant" hidden>
    <header class="asst-head">
      <span class="asst-avatar" aria-hidden="true"><i data-ico="sparkle"></i></span>
      <div class="grow">
        <h2>Repository assistant</h2>
        <p class="asst-sub"><span class="asst-dot" aria-hidden="true"></span> Answers from live data</p>
      </div>
      <button class="btn btn-ghost btn-icon btn-sm" type="button" data-asst-close aria-label="Close the assistant">
        <i data-ico="close" class="ico-sm"></i>
      </button>
    </header>

    <div class="asst-log" data-asst-log role="log" aria-live="polite" tabindex="0"></div>

    <form class="asst-form" data-asst-form>
      <label class="sr-only" for="asst-input">Ask a question about the repository</label>
      <input class="input asst-input" id="asst-input" data-asst-input type="text"
             placeholder="Ask about the repository…" autocomplete="off" maxlength="400">
      <button class="btn btn-primary btn-icon asst-send" type="submit" data-asst-send aria-label="Send">
        <i data-ico="arrowright" class="ico-sm"></i>
      </button>
    </form>

    <p class="asst-foot">
      Answers come from this repository's own records. It will say when it does not know.
    </p>
  </div>
</div>
<script src="<?= e(url('assets/js/assistant.js')) ?>?v=<?= @filemtime(ROOT_PATH . '/assets/js/assistant.js') ?: time() ?>" defer></script>

<?= $extraFoot ?? '' ?>
</body>
</html>
