  </main><!-- /.main -->
</div><!-- /.app -->

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
