/* =============================================================================
   Repository assistant — the widget.

   Talks to assistant.php, which answers from this database and a curated
   knowledge base. There is no language model behind it, so it never invents an
   answer; when it does not recognise a question it says so.

   Failure handling is the important part here. A hung request must never leave
   the composer disabled and a spinner turning forever, so every send has a
   single settled guard and a watchdog.
   ========================================================================== */
(function () {
  'use strict';

  var root = document.querySelector('.asst');
  if (!root) return;

  var endpoint = root.dataset.endpoint;
  var fab   = root.querySelector('[data-asst-toggle]');
  var panel = root.querySelector('.asst-panel');
  var log   = root.querySelector('[data-asst-log]');
  var form  = root.querySelector('[data-asst-form]');
  var input = root.querySelector('[data-asst-input]');
  var send  = root.querySelector('[data-asst-send]');
  if (!fab || !panel || !log || !form || !input) return;

  var busy = false;
  var greeted = false;

  /* ------------------------------------------------------------ open/close */

  function open() {
    root.classList.add('is-open');
    fab.setAttribute('aria-expanded', 'true');
    panel.hidden = false;
    if (!greeted) { greet(); greeted = true; }
    setTimeout(function () { input.focus(); }, 60);
  }
  function close() {
    root.classList.remove('is-open');
    fab.setAttribute('aria-expanded', 'false');
    panel.hidden = true;
    fab.focus();
  }
  fab.addEventListener('click', function () {
    root.classList.contains('is-open') ? close() : open();
  });
  root.querySelectorAll('[data-asst-close]').forEach(function (b) {
    b.addEventListener('click', close);
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && root.classList.contains('is-open')) close();
  });

  /* ------------------------------------------------------------- rendering */

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }

  function bubble(who, html, links, note) {
    var wrap = el('div', 'asst-msg ' + (who === 'me' ? 'asst-me' : 'asst-bot'));

    if (who !== 'me') {
      var av = el('span', 'asst-avatar sm');
      av.innerHTML = window.Icons ? window.Icons.svg('sparkle', 'ico') : '';
      av.setAttribute('aria-hidden', 'true');
      wrap.appendChild(av);
    }

    var body = el('div', 'asst-bubble');
    if (who === 'me') {
      body.textContent = html;                 /* never trust the reader's text */
    } else {
      body.innerHTML = html;                   /* server text only, built by us */
    }

    /* When an answer came from the language model rather than repository data,
       say so on the message. The reader should always know which they are
       looking at. */
    if (who !== 'me' && note) {
      var n = el('p', 'asst-note', note);
      body.appendChild(n);
    }

    if (links && links.length) {
      var box = el('div', 'asst-links');
      links.forEach(function (l) {
        var a = el('a', 'asst-link', l[0]);
        a.href = l[1];
        box.appendChild(a);
      });
      body.appendChild(box);
    }

    wrap.appendChild(body);
    log.appendChild(wrap);
    log.scrollTop = log.scrollHeight;
    return wrap;
  }

  function typing() {
    var wrap = el('div', 'asst-msg asst-bot');
    var av = el('span', 'asst-avatar sm');
    av.innerHTML = window.Icons ? window.Icons.svg('sparkle', 'ico') : '';
    av.setAttribute('aria-hidden', 'true');
    var body = el('div', 'asst-bubble');
    body.innerHTML = '<span class="asst-dots"><span class="asst-dot"></span>'
                   + '<span class="asst-dot"></span><span class="asst-dot"></span></span>';
    body.setAttribute('aria-label', 'Looking that up');
    wrap.appendChild(av); wrap.appendChild(body);
    log.appendChild(wrap);
    log.scrollTop = log.scrollHeight;
    return wrap;
  }

  function suggestions(list) {
    var box = el('div', 'asst-sugg');
    list.forEach(function (text) {
      var b = el('button', 'asst-chip', text);
      b.type = 'button';
      b.addEventListener('click', function () { ask(text); });
      box.appendChild(b);
    });
    log.appendChild(box);
    log.scrollTop = log.scrollHeight;
  }

  function greet() {
    bubble('bot',
      'I can answer questions about this repository — what it holds, how to deposit work, '
      + 'how approval works, and how the Dublin Core, PREMIS and METS standards are applied. '
      + 'I answer from the live database, so the numbers are current.');
    suggestions([
      'How many records are there?',
      'How do I submit my thesis?',
      'What is PREMIS?',
      'How are files kept intact?'
    ]);
  }

  /* ----------------------------------------------------------------- asking */

  function ask(text) {
    text = (text || '').trim();
    if (!text || busy) return;

    bubble('me', text);
    input.value = '';
    busy = true;
    input.disabled = true;
    if (send) send.disabled = true;

    var dots = typing();
    var settled = false;

    function finish(html, links, noteText) {
      if (settled) return;
      settled = true;
      if (dots.parentNode) dots.parentNode.removeChild(dots);
      bubble('bot', html, links, noteText);
      busy = false;
      input.disabled = false;
      if (send) send.disabled = false;
      input.focus();
    }

    /* If the request never settles, the widget still recovers. */
    var watchdog = setTimeout(function () {
      finish('That took too long. The page may have lost its connection to the server — '
           + 'try again, or use the menu to find what you need.');
    }, 12000);

    var body = new URLSearchParams();
    body.set('q', text);

    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin'
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        clearTimeout(watchdog);
        finish(data && data.answer ? data.answer : 'Something went wrong reading that answer.',
               data && data.links, data && data.note);
      })
      .catch(function () {
        clearTimeout(watchdog);
        finish('I could not reach the repository just then. Please try again.');
      });
  }

  /* ============================================================== dragging ==
     The launcher can be moved anywhere on screen. Two rules make this behave:

       · a movement threshold, so a click still opens the panel rather than
         being swallowed as a tiny drag
       · the widget is clamped inside the viewport on drop and on resize, so it
         can never be pushed somewhere it cannot be reached

     Dragging is NOT the only way to move it. With the launcher focused, the
     arrow keys nudge it and Home returns it to the corner — a drag-only
     control is unusable for anyone who cannot operate a pointer precisely.
     ======================================================================= */

  /* Set when a drag ends; expires on its own so it can never strand a click. */
  var suppressClickUntil = 0;

  var STORE = 'sjp2cd-asst-pos';
  var MARGIN = 12;
  var THRESHOLD = 4;

  function size() {
    var r = root.getBoundingClientRect();
    return { w: r.width, h: r.height };
  }

  /* Keep the whole widget on screen whatever the window is doing. */
  function clamp(left, top) {
    var s = size();
    return {
      left: Math.max(MARGIN, Math.min(left, window.innerWidth  - s.w - MARGIN)),
      top:  Math.max(MARGIN, Math.min(top,  window.innerHeight - s.h - MARGIN))
    };
  }

  function place(left, top, remember) {
    var c = clamp(left, top);
    root.style.left = c.left + 'px';
    root.style.top = c.top + 'px';
    root.style.right = 'auto';
    root.style.bottom = 'auto';

    /* Flip the panel's side and direction so it always opens on screen. */
    var s = size();
    root.classList.toggle('is-left', c.left + s.w / 2 < window.innerWidth / 2);
    root.classList.toggle('is-top',  c.top  + s.h / 2 < window.innerHeight / 2);

    if (remember) {
      try { localStorage.setItem(STORE, JSON.stringify({ left: c.left, top: c.top })); } catch (e) {}
    }
  }

  function restore() {
    if (window.innerWidth <= 640) return;      /* small screens keep the corner */
    var saved = null;
    try { saved = JSON.parse(localStorage.getItem(STORE) || 'null'); } catch (e) {}
    if (saved && typeof saved.left === 'number' && typeof saved.top === 'number') {
      place(saved.left, saved.top, false);
    }
  }

  function resetPosition() {
    root.style.left = root.style.top = root.style.right = root.style.bottom = '';
    root.classList.remove('is-left', 'is-top');
    try { localStorage.removeItem(STORE); } catch (e) {}
  }

  /* ---- pointer dragging ---- */
  function makeDraggable(handle) {
    if (!handle) return;
    var startX = 0, startY = 0, originLeft = 0, originTop = 0;
    var dragging = false, moved = false, pointerId = null;

    handle.addEventListener('pointerdown', function (ev) {
      /* Never hijack a click on a real control inside the handle. */
      if (ev.target.closest('button') && ev.target.closest('button') !== handle) return;
      if (ev.button !== 0 || window.innerWidth <= 640) return;

      var r = root.getBoundingClientRect();
      originLeft = r.left; originTop = r.top;
      startX = ev.clientX; startY = ev.clientY;
      dragging = true; moved = false;
      pointerId = ev.pointerId;
      /* Capture keeps the drag alive if the pointer leaves the handle. It can
         throw when the pointer is already gone — a synthetic event, or a
         release that beat us here — and an exception in pointerdown would
         leave the widget stuck mid-drag. */
      try { handle.setPointerCapture(pointerId); } catch (e) { pointerId = null; }
    });

    handle.addEventListener('pointermove', function (ev) {
      if (!dragging) return;
      var dx = ev.clientX - startX, dy = ev.clientY - startY;

      if (!moved && Math.abs(dx) + Math.abs(dy) < THRESHOLD) return;   /* still a click */
      if (!moved) { moved = true; root.classList.add('is-dragging'); }

      place(originLeft + dx, originTop + dy, false);
    });

    function end() {
      if (!dragging) return;
      dragging = false;
      if (pointerId !== null) {
        try { handle.releasePointerCapture(pointerId); } catch (e) {}
        pointerId = null;
      }
      if (moved) {
        root.classList.remove('is-dragging');
        var r = root.getBoundingClientRect();
        place(r.left, r.top, true);
        /* Releasing a drag over the launcher also fires a click, which would
           toggle the panel. Suppress it for a moment — by TIME, not by
           consuming the next click. A one-shot listener that no click ever
           arrives to consume stays armed and eats a later, legitimate one. */
        suppressClickUntil = Date.now() + 300;
      }
    }
    handle.addEventListener('pointerup', end);
    handle.addEventListener('pointercancel', end);
  }

  /* One guard for both handles, checked by time. */
  [fab, panel.querySelector('.asst-head')].forEach(function (h) {
    if (!h) return;
    h.addEventListener('click', function (ev) {
      if (Date.now() < suppressClickUntil) { ev.stopPropagation(); ev.preventDefault(); }
    }, true);
  });

  makeDraggable(fab);
  makeDraggable(panel.querySelector('.asst-head'));

  /* ---- keyboard: the alternative to dragging ---- */
  fab.addEventListener('keydown', function (ev) {
    var step = ev.shiftKey ? 40 : 12;
    var r = root.getBoundingClientRect();
    var handled = true;

    switch (ev.key) {
      case 'ArrowUp':    place(r.left, r.top - step, true); break;
      case 'ArrowDown':  place(r.left, r.top + step, true); break;
      case 'ArrowLeft':  place(r.left - step, r.top, true); break;
      case 'ArrowRight': place(r.left + step, r.top, true); break;
      case 'Home':       resetPosition(); break;
      default: handled = false;
    }
    if (handled) { ev.preventDefault(); }
  });
  fab.setAttribute('title', 'Drag to move, or focus and use the arrow keys. Home returns it to the corner.');

  /* A smaller window must not strand the widget off screen. */
  var resizeTimer = null;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      if (window.innerWidth <= 640) { resetPosition(); return; }
      if (!root.style.left) return;
      var r = root.getBoundingClientRect();
      place(r.left, r.top, true);
    }, 150);
  });

  restore();

  form.addEventListener('submit', function (ev) { ev.preventDefault(); ask(input.value); });

  /* Open it from anywhere: <button data-asst-open> */
  document.querySelectorAll('[data-asst-open]').forEach(function (b) {
    b.addEventListener('click', function (ev) { ev.preventDefault(); open(); });
  });
})();
