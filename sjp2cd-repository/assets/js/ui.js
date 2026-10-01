/* SJP2CD Academic Research Repository — shared behaviour layer.
   No frameworks, no CDN. Every page loads icons.js, data.js, then this file. */
(function () {
  'use strict';

  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------------------------------------------------------------- Theme */
  var Theme = {
    key: 'sjp2cd-theme',
    get: function () { try { return localStorage.getItem(Theme.key); } catch (e) { return null; } },
    set: function (v) {
      try { v ? localStorage.setItem(Theme.key, v) : localStorage.removeItem(Theme.key); } catch (e) {}
      if (v) { document.documentElement.setAttribute('data-theme', v); }
      else   { document.documentElement.removeAttribute('data-theme'); }
      Theme.sync();
    },
    current: function () {
      /* Light is the default, matching the prototype figures in the paper.
         Only an explicit choice moves off it, so the system looks the same on
         every machine it is demonstrated on. */
      return document.documentElement.getAttribute('data-theme') || 'light';
    },
    sync: function () {
      $$('[data-theme-toggle]').forEach(function (b) {
        var dark = Theme.current() === 'dark';
        b.setAttribute('aria-pressed', String(dark));
        b.setAttribute('aria-label', dark ? 'Switch to light appearance' : 'Switch to dark appearance');
        b.innerHTML = window.Icons.svg(dark ? 'sun' : 'moon', 'ico');
        b.firstChild.setAttribute('aria-hidden', 'true');
      });
    },
    init: function () {
      var stored = Theme.get();
      if (stored) document.documentElement.setAttribute('data-theme', stored);
      Theme.sync();
      $$('[data-theme-toggle]').forEach(function (b) {
        b.addEventListener('click', function () { Theme.set(Theme.current() === 'dark' ? 'light' : 'dark'); });
      });
    }
  };

  /* ---------------------------------------------------------------- Toast */
  function toast(msg, kind, title) {
    var host = $('.toasts');
    if (!host) { host = document.createElement('div'); host.className = 'toasts'; document.body.appendChild(host); }
    var icons = { ok: 'checkcircle', warn: 'alert', err: 'xcircle', info: 'info' };
    var t = document.createElement('div');
    t.className = 'toast ' + (kind || 'info');
    t.setAttribute('role', 'status');
    t.innerHTML = window.Icons.svg(icons[kind] || 'info', 'ico') +
      '<div class="grow"><div class="list-title">' + (title || '') + '</div>' +
      '<div class="small muted">' + msg + '</div></div>' +
      '<button class="btn btn-ghost btn-icon btn-sm" aria-label="Dismiss notification">' +
      window.Icons.svg('close', 'ico ico-sm') + '</button>';
    t.querySelector('svg').setAttribute('aria-hidden', 'true');
    host.appendChild(t);
    var kill = function () { if (t.parentNode) t.parentNode.removeChild(t); };
    t.querySelector('button').addEventListener('click', kill);
    setTimeout(kill, 4600);
  }
  window.toast = toast;

  /* --------------------------------------------------------- Sticky header */
  function initHeader() {
    var h = $('.site-header');
    if (!h) return;
    var onScroll = function () { h.classList.toggle('is-stuck', window.scrollY > 8); };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });

    var tog = $('[data-mobile-toggle]');
    var nav = $('.mobile-nav');
    if (tog && nav) {
      tog.addEventListener('click', function () {
        var open = nav.classList.toggle('open');
        tog.setAttribute('aria-expanded', String(open));
      });
    }
  }

  /* -------------------------------------------------------- Sidebar drawer */
  function initSidebar() {
    var side = $('.sidebar');
    var tog = $('[data-sidebar-toggle]');
    if (!side || !tog) return;
    var scrim = null;

    function close() {
      side.classList.remove('open');
      tog.setAttribute('aria-expanded', 'false');
      if (scrim) { scrim.remove(); scrim = null; }
      tog.focus();
    }
    function open() {
      side.classList.add('open');
      tog.setAttribute('aria-expanded', 'true');
      scrim = document.createElement('div');
      scrim.className = 'scrim';
      scrim.addEventListener('click', close);
      document.body.appendChild(scrim);
      var first = side.querySelector('a, button');
      if (first) first.focus();
    }
    tog.addEventListener('click', function () {
      side.classList.contains('open') ? close() : open();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && side.classList.contains('open')) close();
    });
  }

  /* ----------------------------------------------------------- Disclosures */
  /* Works for FAQ (.faq-q) and facet headers (.facet-head): the button
     controls the element named in aria-controls. */
  function initDisclosures() {
    $$('[aria-controls][aria-expanded]').forEach(function (btn) {
      var panel = document.getElementById(btn.getAttribute('aria-controls'));
      if (!panel) return;
      panel.hidden = btn.getAttribute('aria-expanded') !== 'true';
      btn.addEventListener('click', function () {
        var open = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', String(!open));
        panel.hidden = open;
      });
    });
  }

  /* ------------------------------------------------------------------ Tabs */
  function initTabs() {
    $$('[role="tablist"]').forEach(function (list) {
      var tabs = $$('[role="tab"]', list);
      function select(tab) {
        tabs.forEach(function (t) {
          var on = t === tab;
          t.setAttribute('aria-selected', String(on));
          t.tabIndex = on ? 0 : -1;
          var p = document.getElementById(t.getAttribute('aria-controls'));
          if (p) p.hidden = !on;
        });
      }
      tabs.forEach(function (t, i) {
        t.addEventListener('click', function () { select(t); });
        t.addEventListener('keydown', function (e) {
          var n = null;
          if (e.key === 'ArrowRight') n = tabs[(i + 1) % tabs.length];
          if (e.key === 'ArrowLeft')  n = tabs[(i - 1 + tabs.length) % tabs.length];
          if (e.key === 'Home')       n = tabs[0];
          if (e.key === 'End')        n = tabs[tabs.length - 1];
          if (n) { e.preventDefault(); select(n); n.focus(); }
        });
      });
      var init = tabs.filter(function (t) { return t.getAttribute('aria-selected') === 'true'; })[0] || tabs[0];
      if (init) select(init);
    });
  }

  /* ---------------------------------------------------------------- Reveal */
  function initReveal() {
    var els = $$('.reveal');
    if (!els.length) return;
    if (reduceMotion || !('IntersectionObserver' in window)) {
      els.forEach(function (e) { e.classList.add('in'); });
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        var d = parseInt(en.target.getAttribute('data-delay') || '0', 10);
        setTimeout(function () { en.target.classList.add('in'); }, d);
        io.unobserve(en.target);
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    els.forEach(function (e) { io.observe(e); });
  }

  /* -------------------------------------------------------------- Count-up */
  function initCounters() {
    var els = $$('[data-count]');
    if (!els.length) return;
    function run(el) {
      var target = parseFloat(el.getAttribute('data-count'));
      var dec = (el.getAttribute('data-dec') | 0);
      var suffix = el.getAttribute('data-suffix') || '';
      if (reduceMotion) { el.textContent = target.toLocaleString(undefined, { minimumFractionDigits: dec, maximumFractionDigits: dec }) + suffix; return; }
      var start = performance.now(), dur = 1100;
      (function step(now) {
        var p = Math.min((now - start) / dur, 1);
        var e = 1 - Math.pow(1 - p, 3);
        el.textContent = (target * e).toLocaleString(undefined, { minimumFractionDigits: dec, maximumFractionDigits: dec }) + suffix;
        if (p < 1) requestAnimationFrame(step);
      })(start);
    }
    if (!('IntersectionObserver' in window)) { els.forEach(run); return; }
    var io = new IntersectionObserver(function (en) {
      en.forEach(function (x) { if (x.isIntersecting) { run(x.target); io.unobserve(x.target); } });
    }, { threshold: 0.4 });
    els.forEach(function (e) { io.observe(e); });
  }

  /* ---------------------------------------------------------- Bars / rings */
  function initCharts() {
    var bars = $$('[data-bar]');
    if (bars.length) {
      var draw = function () { bars.forEach(function (b) { b.style.height = b.getAttribute('data-bar') + '%'; }); };
      if ('IntersectionObserver' in window && !reduceMotion) {
        var io = new IntersectionObserver(function (en) {
          en.forEach(function (x) { if (x.isIntersecting) { draw(); io.disconnect(); } });
        }, { threshold: 0.25 });
        if (bars[0]) io.observe(bars[0].closest('.bars') || bars[0]);
      } else { draw(); }
    }
    $$('[data-fill]').forEach(function (f) {
      setTimeout(function () { f.style.width = f.getAttribute('data-fill') + '%'; }, 120);
    });
    $$('[data-ring]').forEach(function (r) { r.style.setProperty('--p', r.getAttribute('data-ring')); });
  }

  /* ---------------------------------------------------------------- Copy */
  function initCopy() {
    $$('[data-copy]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var sel = btn.getAttribute('data-copy');
        var src = sel.charAt(0) === '#' ? document.getElementById(sel.slice(1)) : null;
        var text = src ? (src.innerText || src.textContent) : sel;
        var done = function () { toast('Copied to clipboard.', 'ok', 'Copied'); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text.trim()).then(done, function () { toast('Copy failed — select the text manually.', 'warn', 'Could not copy'); });
        } else {
          var ta = document.createElement('textarea');
          ta.value = text.trim(); document.body.appendChild(ta); ta.select();
          try { document.execCommand('copy'); done(); } catch (e) { toast('Copy failed.', 'warn', 'Could not copy'); }
          document.body.removeChild(ta);
        }
      });
    });
  }

  /* ------------------------------------------------------- Demo-only guard */
  /* Buttons marked data-demo don't have a backend yet — say so, don't dead-end. */
  function initDemoActions() {
    $$('[data-demo]').forEach(function (b) {
      b.addEventListener('click', function (e) {
        if (b.tagName === 'A') e.preventDefault();
        toast(b.getAttribute('data-demo') || 'This action needs the backend service, which is not part of the prototype.', 'info', 'Prototype');
      });
    });
  }

  /* ------------------------------------------------------------------ Tilt */
  /* Turns a panel toward the pointer. The rotation is written as two custom
     properties and applied by CSS, so the whole effect disappears cleanly
     under reduced motion or on a touch device without touching the markup.
     Reads happen on pointermove but the write is deferred to the next frame,
     so a fast cursor cannot force layout work on every event. */
  function initTilt() {
    if (reduceMotion) return;
    if (window.matchMedia && !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;

    var MAX = parseFloat(getComputedStyle(document.documentElement)
                .getPropertyValue('--tilt-max')) || 5;

    $$('[data-tilt]').forEach(function (el) {
      var frame = null, rect = null;

      function apply(x, y) {
        frame = null;
        if (!rect) return;
        var px = (x - rect.left) / rect.width  - 0.5;
        var py = (y - rect.top)  / rect.height - 0.5;
        el.style.setProperty('--ry', ( px * MAX * 2).toFixed(2) + 'deg');
        el.style.setProperty('--rx', (-py * MAX * 2).toFixed(2) + 'deg');
      }

      el.addEventListener('pointerenter', function () { rect = el.getBoundingClientRect(); });

      el.addEventListener('pointermove', function (ev) {
        if (frame) return;
        frame = requestAnimationFrame(function () { apply(ev.clientX, ev.clientY); });
      });

      function rest() {
        if (frame) { cancelAnimationFrame(frame); frame = null; }
        el.style.setProperty('--rx', '0deg');
        el.style.setProperty('--ry', '0deg');
      }
      el.addEventListener('pointerleave', rest);
      /* A panel must never stay tilted while the reader is tabbing through it. */
      el.addEventListener('focusin', rest);
    });
  }

  /* ----------------------------------------------------------- Long facets
     A facet is ordered by how much each value holds, which is the whole point
     of it -- but that makes a name outside the first few unreachable. The tail
     is present in the page and folded away; typing in the filter box searches
     the entire list, not just the part on screen. Works as a plain list if
     this script never runs. */
  function initFacetFilter() {
    $$('[data-facet-filter]').forEach(function (facet) {
      var find = facet.querySelector('[data-facet-find]');
      var more = facet.querySelector('[data-facet-more]');
      var none = facet.querySelector('[data-facet-none]');
      var opts = Array.prototype.slice.call(facet.querySelectorAll('.facet-opt'));
      if (!opts.length) return;

      var expanded = false;

      function render() {
        var term = (find && find.value || '').trim().toLowerCase();
        var shown = 0;

        opts.forEach(function (opt) {
          var label = opt.querySelector('.truncate');
          var name = (label && label.textContent || '').toLowerCase();
          var hit = term === '' || name.indexOf(term) !== -1;
          /* While filtering, the fold is ignored: a match is a match wherever
             it sits in the list. */
          var visible = hit && (term !== '' || expanded || !opt.classList.contains('is-folded'));
          opt.hidden = !visible;
          if (hit) shown++;
        });

        if (none) none.hidden = !(term !== '' && shown === 0);
        if (more) more.hidden = term !== '';
      }

      if (find) {
        find.addEventListener('input', render);
        /* Escape clears the box rather than closing something unexpected. */
        find.addEventListener('keydown', function (ev) {
          if (ev.key === 'Escape' && find.value !== '') { ev.stopPropagation(); find.value = ''; render(); }
        });
      }

      if (more) {
        more.addEventListener('click', function () {
          expanded = !expanded;
          more.setAttribute('aria-expanded', String(expanded));
          more.textContent = expanded ? more.dataset.less : more.dataset.more;
          render();
        });
      }

      render();
    });
  }

  /* ------------------------------------------------------ Search suggestions
     An ordinary search form that gains a suggestion list. The form still
     submits normally, so nothing here is required for search to work: with no
     script, or a failed request, typing and pressing Enter behaves exactly as
     it did before.

     Built as a combobox so it is operable from the keyboard -- arrow keys move
     through the list, Enter takes the highlighted suggestion, Escape closes it
     and leaves what was typed. */
  function initSuggest() {
    $$('[data-suggest]').forEach(function (input) {
      var box = document.createElement('div');
      box.className = 'suggest';
      box.setAttribute('role', 'listbox');
      box.hidden = true;

      var wrap = input.closest('.input-icon') || input.parentNode;
      wrap.style.position = wrap.style.position || 'relative';
      wrap.appendChild(box);

      input.setAttribute('role', 'combobox');
      input.setAttribute('aria-autocomplete', 'list');
      input.setAttribute('aria-expanded', 'false');
      input.setAttribute('autocomplete', 'off');

      var items = [];          /* the anchors currently on offer */
      var active = -1;
      var timer = null;
      var seq = 0;             /* guards against a slow reply overwriting a fast one */

      function close() {
        box.hidden = true; box.innerHTML = ''; items = []; active = -1;
        input.setAttribute('aria-expanded', 'false');
      }

      function highlight(i) {
        if (!items.length) return;
        if (active > -1) items[active].classList.remove('is-active');
        active = (i + items.length) % items.length;
        items[active].classList.add('is-active');
        items[active].scrollIntoView({ block: 'nearest' });
      }

      function render(data) {
        box.innerHTML = '';
        items = [];
        if (!data.groups || !data.groups.length) { close(); return; }

        data.groups.forEach(function (g) {
          var h = document.createElement('div');
          h.className = 'suggest-head';
          h.textContent = g.label;
          box.appendChild(h);

          g.items.forEach(function (it) {
            var a = document.createElement('a');
            a.className = 'suggest-item';
            a.href = it.href;
            a.setAttribute('role', 'option');

            var label = document.createElement('span');
            label.className = 'suggest-label';
            /* The typed run is marked in the suggestion so it is obvious why
               each one is being offered. Built as text nodes, never as HTML,
               because this text came out of the database. */
            var name = it.label;
            var at = name.toLowerCase().indexOf(data.q.toLowerCase());
            if (at === -1) {
              label.textContent = name;
            } else {
              label.appendChild(document.createTextNode(name.slice(0, at)));
              var m = document.createElement('mark');
              m.textContent = name.slice(at, at + data.q.length);
              label.appendChild(m);
              label.appendChild(document.createTextNode(name.slice(at + data.q.length)));
            }
            a.appendChild(label);

            if (it.note) {
              var n = document.createElement('span');
              n.className = 'suggest-note';
              n.textContent = it.note;
              a.appendChild(n);
            }

            box.appendChild(a);
            items.push(a);
          });
        });

        box.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        active = -1;
      }

      function ask() {
        var q = input.value.trim();
        if (q.length < 2) { close(); return; }
        var mine = ++seq;
        fetch(input.dataset.suggest + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (d) { if (d && mine === seq) render(d); })
          .catch(function () { /* the form still submits; say nothing */ });
      }

      input.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(ask, 160);
      });

      input.addEventListener('keydown', function (ev) {
        if (box.hidden) return;
        if (ev.key === 'ArrowDown')      { ev.preventDefault(); highlight(active + 1); }
        else if (ev.key === 'ArrowUp')   { ev.preventDefault(); highlight(active - 1); }
        else if (ev.key === 'Escape')    { ev.stopPropagation(); close(); }
        else if (ev.key === 'Enter' && active > -1) {
          ev.preventDefault();
          window.location.href = items[active].href;
        }
      });

      input.addEventListener('blur', function () { setTimeout(close, 140); });
      document.addEventListener('click', function (ev) {
        if (!wrap.contains(ev.target)) close();
      });
    });
  }

  /* ------------------------------------------------------- Citation styles
     The copy button points at whichever style is showing, so there is one
     button rather than three. With no script every style is still in the page;
     they are simply all visible instead of one at a time. */
  function initCite() {
    $$('[data-cite]').forEach(function (card) {
      var tabs = $$('[data-cite-style]', card);
      var copy = card.querySelector('[data-cite-copy]');
      if (!tabs.length) return;

      tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
          var id = 'cite-' + tab.dataset.citeStyle;

          tabs.forEach(function (t) {
            var on = t === tab;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', String(on));
          });
          $$('.cite-text', card).forEach(function (pane) { pane.hidden = pane.id !== id; });
          if (copy) copy.setAttribute('data-copy', '#' + id);
        });
      });
    });
  }

  /* ------------------------------------------------------------ Bootstrap */
  document.addEventListener('DOMContentLoaded', function () {
    window.Icons.mount();
    Theme.init();
    initHeader();
    initSidebar();
    initDisclosures();
    initTabs();
    initReveal();
    initCounters();
    initCharts();
    initCopy();
    initDemoActions();
    initTilt();
    initFacetFilter();
    initSuggest();
    initCite();
    if (window.PAGE && typeof window.PAGE.init === 'function') window.PAGE.init();
  });
})();
