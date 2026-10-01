/* =============================================================================
   Live chat client.

   Polls chat-api.php while the tab is visible, sends optimistically, and shows
   a typing indicator. Every network call is guarded: a request that never
   settles must not leave the composer disabled forever.
   ========================================================================== */
(function () {
  'use strict';

  var root = document.querySelector('.chat');
  if (!root) return;

  var endpoint = root.dataset.endpoint;
  var csrf     = root.dataset.csrf;
  var withId   = parseInt(root.dataset.with, 10) || 0;
  var lastId   = parseInt(root.dataset.last, 10) || 0;

  var log     = root.querySelector('[data-log]');
  var form    = root.querySelector('[data-form]');
  var input   = root.querySelector('[data-input]');
  var sendBtn = root.querySelector('[data-send]');
  var typing  = root.querySelector('[data-typing]');
  var errBox  = root.querySelector('[data-error]');
  var empty   = root.querySelector('[data-empty]');
  var filter  = document.getElementById('people-filter');
  var noneMsg = root.querySelector('.chat-none');

  var POLL_ACTIVE = 3000;   // tab in front
  var POLL_IDLE   = 15000;  // tab hidden
  var timer       = null;
  var polling     = false;
  var lastDay     = '';

  /* Establish the day heading already on screen, so poll results continue it. */
  var days = log ? log.querySelectorAll('.chat-day span') : [];
  if (days.length) lastDay = days[days.length - 1].textContent.trim();

  /* ----------------------------------------------------------- helpers --- */

  function scrollToEnd(smooth) {
    if (!log) return;
    log.scrollTo({ top: log.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
  }

  function showError(message) {
    if (!errBox) return;
    errBox.textContent = message;
    errBox.hidden = false;
  }

  function clearError() {
    if (errBox) errBox.hidden = true;
  }

  /** fetch with a hard timeout, so a hung request always settles. */
  function request(url, options, ms) {
    var controller = new AbortController();
    var timeout = setTimeout(function () { controller.abort(); }, ms || 12000);
    options = options || {};
    options.signal = controller.signal;
    options.credentials = 'same-origin';

    return fetch(url, options)
      .then(function (res) {
        return res.json().catch(function () {
          throw new Error('The server sent something unreadable.');
        });
      })
      .finally(function () { clearTimeout(timeout); });
  }

  function bubble(msg, pending) {
    var wrap = document.createElement('div');
    wrap.className = 'chat-msg' + (msg.mine ? ' is-mine' : '') + (pending ? ' is-pending' : '');
    if (msg.id) wrap.dataset.id = msg.id;

    var body = document.createElement('div');
    body.className = 'chat-bubble';
    body.textContent = msg.body;          // textContent, never innerHTML

    var time = document.createElement('p');
    time.className = 'chat-time';
    time.textContent = pending
      ? 'Sending…'
      : msg.time + (msg.mine ? (msg.read ? ' · Read' : ' · Sent') : '');

    wrap.appendChild(body);
    wrap.appendChild(time);
    return wrap;
  }

  function dayHeading(label) {
    var p = document.createElement('p');
    p.className = 'chat-day';
    var span = document.createElement('span');
    span.textContent = label;
    p.appendChild(span);
    return p;
  }

  function append(msg) {
    if (!log) return;
    if (empty && !empty.hidden) empty.hidden = true;

    if (msg.day && msg.day !== lastDay) {
      lastDay = msg.day;
      log.insertBefore(dayHeading(msg.day), typing || null);
    }
    log.insertBefore(bubble(msg, false), typing || null);
  }

  /* Already-rendered ids, so a poll never duplicates an optimistic bubble. */
  function has(id) {
    return !!log.querySelector('.chat-msg[data-id="' + id + '"]');
  }

  /* -------------------------------------------------------------- poll --- */

  function poll() {
    if (polling || !withId) return Promise.resolve();
    polling = true;

    var url = endpoint + '?action=poll&with=' + withId + '&since=' + lastId;

    return request(url, { method: 'GET' }, 12000)
      .then(function (data) {
        if (!data || !data.ok) {
          if (data && data.error) showError(data.error);
          return;
        }
        clearError();

        var near = log ? (log.scrollHeight - log.scrollTop - log.clientHeight < 140) : true;
        var added = false;

        (data.messages || []).forEach(function (m) {
          if (m.id > lastId) lastId = m.id;
          if (has(m.id)) return;
          append(m);
          added = true;
        });

        /* Refresh the read receipt on messages I already have on screen. */
        (data.messages || []).forEach(function (m) {
          if (!m.mine) return;
          var el = log.querySelector('.chat-msg[data-id="' + m.id + '"] .chat-time');
          if (el) el.textContent = m.time + (m.read ? ' · Read' : ' · Sent');
        });

        if (typing) {
          typing.hidden = !data.typing;
          if (data.typing && near) scrollToEnd(true);
        }
        if (added && near) scrollToEnd(true);

        updateBadges(data.unread || {});
        updateNav(data.total || 0, data.notes || 0);
      })
      .catch(function () {
        /* A dropped poll is not worth shouting about; the next one retries. */
      })
      .finally(function () { polling = false; });
  }

  function updateBadges(unread) {
    root.querySelectorAll('[data-unread]').forEach(function (el) {
      var n = unread[el.dataset.unread] || 0;
      el.textContent = n;
      el.hidden = n === 0;
    });
  }

  /* Keep the sidebar counts honest without a page reload. */
  function updateNav(messages, notes) {
    setNavBadge('messages.php', messages);
    setNavBadge('notifications.php', notes);
  }

  function setNavBadge(page, n) {
    var link = document.querySelector('.sidebar .nav-item[href$="' + page + '"]');
    if (!link) return;
    var badge = link.querySelector('.badge');
    if (n > 0) {
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'badge badge-warn';
        link.appendChild(badge);
      }
      badge.textContent = n > 9 ? '9+' : String(n);
    } else if (badge) {
      badge.remove();
    }
  }

  function schedule() {
    clearTimeout(timer);
    timer = setTimeout(function () {
      poll().finally(schedule);
    }, document.hidden ? POLL_IDLE : POLL_ACTIVE);
  }

  /* -------------------------------------------------------------- send --- */

  function send() {
    var text = (input.value || '').trim();
    if (!text || !withId) return;

    var settled = false;
    var optimistic = bubble({ mine: true, body: text }, true);
    if (empty && !empty.hidden) empty.hidden = true;
    log.insertBefore(optimistic, typing || null);
    scrollToEnd(true);

    input.value = '';
    resize();
    input.disabled = true;
    sendBtn.disabled = true;
    clearError();

    var body = new URLSearchParams();
    body.set('action', 'send');
    body.set('with', String(withId));
    body.set('body', text);
    body.set('_csrf', csrf);

    function release() {
      if (settled) return;
      settled = true;
      input.disabled = false;
      sendBtn.disabled = false;
      input.focus();
    }

    /* Belt and braces: even a request that never settles frees the composer. */
    var watchdog = setTimeout(function () {
      release();
      optimistic.classList.remove('is-pending');
      optimistic.classList.add('is-failed');
      optimistic.querySelector('.chat-time').textContent = 'Not sent';
      showError('That took too long to send. Check your connection and try again.');
    }, 15000);

    request(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }, 12000)
      .then(function (data) {
        if (settled) return;
        if (!data || !data.ok) {
          optimistic.classList.remove('is-pending');
          optimistic.classList.add('is-failed');
          optimistic.querySelector('.chat-time').textContent = 'Not sent';
          showError((data && data.error) || 'The message did not send.');
          return;
        }
        optimistic.classList.remove('is-pending');
        optimistic.dataset.id = data.message.id;
        optimistic.querySelector('.chat-time').textContent = data.message.time + ' · Sent';
        if (data.message.id > lastId) lastId = data.message.id;
        if (data.message.day !== lastDay) lastDay = data.message.day;
      })
      .catch(function () {
        if (settled) return;
        optimistic.classList.remove('is-pending');
        optimistic.classList.add('is-failed');
        optimistic.querySelector('.chat-time').textContent = 'Not sent';
        showError('The message did not send. Your connection may have dropped.');
      })
      .finally(function () {
        clearTimeout(watchdog);
        release();
        scrollToEnd(true);
      });
  }

  /* ------------------------------------------------------- typing ping --- */

  var typingSentAt = 0;
  var stopTimer = null;

  function pingTyping(stop) {
    if (!withId) return;
    var body = new URLSearchParams();
    body.set('action', 'typing');
    body.set('with', String(withId));
    body.set('_csrf', csrf);
    if (stop) body.set('stop', '1');

    request(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }, 6000).catch(function () { /* a lost typing ping changes nothing */ });
  }

  function onTyping() {
    var now = Date.now();
    if (now - typingSentAt > 3000) {
      typingSentAt = now;
      pingTyping(false);
    }
    clearTimeout(stopTimer);
    stopTimer = setTimeout(function () { pingTyping(true); typingSentAt = 0; }, 4000);
  }

  /* --------------------------------------------------------- composing --- */

  function resize() {
    if (!input) return;
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 160) + 'px';
  }

  if (form) {
    form.addEventListener('submit', function (ev) { ev.preventDefault(); send(); });
  }

  if (input) {
    input.addEventListener('input', function () { resize(); onTyping(); });
    input.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && !ev.shiftKey) {
        ev.preventDefault();
        send();
      }
    });
    input.addEventListener('blur', function () {
      clearTimeout(stopTimer);
      if (typingSentAt) { pingTyping(true); typingSentAt = 0; }
    });
    resize();
  }

  /* --------------------------------------------------- filter contacts --- */

  if (filter) {
    filter.addEventListener('input', function () {
      var term = filter.value.trim().toLowerCase();
      var shown = 0;
      root.querySelectorAll('.chat-person').forEach(function (el) {
        var hit = !term || (el.dataset.name || '').indexOf(term) !== -1;
        el.hidden = !hit;
        if (hit) shown++;
      });
      if (noneMsg) noneMsg.hidden = shown !== 0;
    });
  }

  /* ------------------------------------------------------------- start --- */

  scrollToEnd(false);
  schedule();

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { poll(); }
    schedule();
  });

  window.addEventListener('beforeunload', function () {
    if (typingSentAt && navigator.sendBeacon) {
      var fd = new FormData();
      fd.append('action', 'typing');
      fd.append('with', String(withId));
      fd.append('stop', '1');
      fd.append('_csrf', csrf);
      navigator.sendBeacon(endpoint, fd);
    }
  });
})();
