/* SJP2CD Repository — inline SVG icon set (single stroke family, 24x24 grid).
   Usage:  <i data-ico="search"></i>            -> decorative (aria-hidden)
           <i data-ico="search" data-label="Search"></i> -> meaningful (role=img + title)
   Icons are injected at DOMContentLoaded so no external file/CDN is required. */
(function (global) {
  'use strict';

  var P = {
    /* navigation & layout */
    home:        '<path d="M3 10.2 12 3l9 7.2V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
    grid:        '<rect x="3" y="3" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="2"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="2"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="2"/>',
    list:        '<path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
    menu:        '<path d="M3 6h18M3 12h18M3 18h18"/>',
    close:       '<path d="M18 6 6 18M6 6l12 12"/>',
    chevdown:    '<path d="m6 9 6 6 6-6"/>',
    chevright:   '<path d="m9 6 6 6-6 6"/>',
    chevleft:    '<path d="m15 6-6 6 6 6"/>',
    arrowright:  '<path d="M4 12h16m-6-6 6 6-6 6"/>',
    arrowleft:   '<path d="M20 12H4m6 6-6-6 6-6"/>',
    arrowup:     '<path d="M12 20V4m-6 6 6-6 6 6"/>',
    arrowdown:   '<path d="M12 4v16m6-6-6 6-6-6"/>',
    external:    '<path d="M14 4h6v6M20 4l-8.5 8.5"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
    plus:        '<path d="M12 5v14M5 12h14"/>',
    more:        '<circle cx="5" cy="12" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="19" cy="12" r="1.4"/>',

    /* documents & repository */
    file:        '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h4"/>',
    filestack:   '<path d="M9 3h6l4 4v9a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M15 3v4h4"/><path d="M4 8v11a2 2 0 0 0 2 2h9"/>',
    folder:      '<path d="M3 7a2 2 0 0 1 2-2h4l2 2.5h8a2 2 0 0 1 2 2V18a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
    book:        '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 19a2 2 0 0 1 2-2h13"/><path d="M9 7h6"/>',
    archive:     '<rect x="3" y="4" width="18" height="5" rx="1.5"/><path d="M5 9v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V9"/><path d="M10 13h4"/>',
    upload:      '<path d="M21 15v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3"/><path d="M12 3v13m-5-8 5-5 5 5"/>',
    download:    '<path d="M21 15v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-3"/><path d="M12 16V3m-5 8 5 5 5-5"/>',
    quote:       '<path d="M9 7H6a3 3 0 0 0-3 3v3a3 3 0 0 0 3 3h1a2 2 0 0 1 2 2v0a2 2 0 0 1-2 2"/><path d="M20 7h-3a3 3 0 0 0-3 3v3a3 3 0 0 0 3 3h1a2 2 0 0 1 2 2v0a2 2 0 0 1-2 2"/>',
    tag:         '<path d="M20.5 12.5 12 21l-9-9V4a1 1 0 0 1 1-1h8z"/><circle cx="8" cy="8" r="1.4"/>',
    bookmark:    '<path d="M6 3h12a1 1 0 0 1 1 1v17l-7-4.5L5 21V4a1 1 0 0 1 1-1z"/>',
    link:        '<path d="M10.5 13.5a4 4 0 0 0 5.7 0l2.8-2.8a4 4 0 1 0-5.7-5.7l-1.6 1.6"/><path d="M13.5 10.5a4 4 0 0 0-5.7 0l-2.8 2.8a4 4 0 1 0 5.7 5.7l1.6-1.6"/>',
    hash:        '<path d="M5 9h14M5 15h14M10 3 8 21M16 3l-2 18"/>',

    /* search & filter */
    search:      '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/>',
    filter:      '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>',
    sliders:     '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>',
    sort:        '<path d="M7 4v16m0 0-3-3m3 3 3-3M17 20V4m0 0-3 3m3-3 3 3"/>',

    /* people & roles */
    user:        '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    users:       '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 21a6.5 6.5 0 0 1 13 0"/><path d="M16 5.2a3.5 3.5 0 0 1 0 6.6M18 21a6.6 6.6 0 0 0-2.2-4.9"/>',
    cap:         '<path d="m12 4 10 5-10 5L2 9z"/><path d="M6 11v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5"/>',
    shieldcheck: '<path d="M12 3l8 3v6c0 4.6-3.2 7.9-8 9-4.8-1.1-8-4.4-8-9V6z"/><path d="m9 12 2 2 4-4"/>',
    key:         '<circle cx="8" cy="14" r="4"/><path d="m11 11 8-8 2 2-1.5 1.5L21 8l-2 2-1.5-1.5L16 10"/>',
    idcard:      '<rect x="2.5" y="5" width="19" height="14" rx="2"/><circle cx="8.5" cy="11" r="2.2"/><path d="M5 16.4a3.8 3.8 0 0 1 7 0M14.5 10h4M14.5 14h4"/>',

    /* status */
    check:       '<path d="m5 13 4.5 4.5L19 7"/>',
    checkcircle: '<circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
    xcircle:     '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/>',
    clock:       '<circle cx="12" cy="12" r="9"/><path d="M12 7v5.3l3.2 2"/>',
    alert:       '<path d="M10.3 3.9 2.6 17.3A2 2 0 0 0 4.3 20.3h15.4a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4.5M12 17h.01"/>',
    info:        '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7.8h.01"/>',
    help:        '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.4 2.3c-.6.3-.9.8-.9 1.5v.4M12 17h.01"/>',
    bell:        '<path d="M18 9a6 6 0 1 0-12 0c0 5-2 6-2 6h16s-2-1-2-6"/><path d="M13.7 20a2 2 0 0 1-3.4 0"/>',
    refresh:     '<path d="M20 11a8 8 0 0 0-14-4.3L3.5 9"/><path d="M4 13a8 8 0 0 0 14 4.3L20.5 15"/><path d="M3.5 5v4h4M20.5 19v-4h-4"/>',
    history:     '<path d="M3.5 9A8.5 8.5 0 1 1 3 13"/><path d="M3.5 4.5V9H8"/><path d="M12 8v4.4l3 1.8"/>',

    /* preservation / technical */
    database:    '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
    code:        '<path d="m9 18-6-6 6-6"/><path d="m15 6 6 6-6 6"/>',
    layers:      '<path d="m12 3 9 4.5-9 4.5-9-4.5z"/><path d="m3.5 12 8.5 4.3 8.5-4.3"/><path d="m3.5 16.5 8.5 4.3 8.5-4.3"/>',
    shield:      '<path d="M12 3l8 3v6c0 4.6-3.2 7.9-8 9-4.8-1.1-8-4.4-8-9V6z"/>',
    cpu:         '<rect x="6" y="6" width="12" height="12" rx="2"/><rect x="9.5" y="9.5" width="5" height="5" rx="1"/><path d="M9 3v3M15 3v3M9 18v3M15 18v3M3 9h3M3 15h3M18 9h3M18 15h3"/>',
    cloud:       '<path d="M7 18a4 4 0 0 1-.4-8A6 6 0 0 1 18 9.5 3.5 3.5 0 0 1 17.5 18z"/>',
    fingerprint: '<path d="M12 4a8 8 0 0 0-8 8v2"/><path d="M20 13v-1a8 8 0 0 0-4-6.9"/><path d="M8 12a4 4 0 0 1 8 0v3a6 6 0 0 1-.6 2.6"/><path d="M12 12v4M8.5 19a8 8 0 0 0 1.3-2.6"/>',
    lock:        '<rect x="4.5" y="10" width="15" height="10" rx="2"/><path d="M8 10V7.5a4 4 0 0 1 8 0V10"/>',
    logout:      '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 16l-4-4 4-4M6 12h9"/>',
    settings:    '<circle cx="12" cy="12" r="3"/><path d="M19.4 14.5a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1v.3a2 2 0 1 1-4 0v-.2a1.6 1.6 0 0 0-2.8-1.1l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.6 1.6 0 0 0-1.1-2.7H3.5a2 2 0 1 1 0-4h.2a1.6 1.6 0 0 0 1.1-2.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.6 1.6 0 0 0 2.7-1.1V3.5a2 2 0 1 1 4 0v.2a1.6 1.6 0 0 0 2.8 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0 1.1 2.7h.3a2 2 0 1 1 0 4h-.2a1.6 1.6 0 0 0-1.5 1z"/>',

    /* data & analytics */
    chart:       '<path d="M3 3v16.5a1.5 1.5 0 0 0 1.5 1.5H21"/><path d="M7 15.5V11M11.5 15.5V7M16 15.5v-6M20.5 15.5V4.5"/>',
    trend:       '<path d="M3 17l5.5-5.5 3.5 3.5L21 6"/><path d="M15.5 6H21v5.5"/>',
    pie:         '<path d="M12 3a9 9 0 1 0 9 9h-9z"/><path d="M15 3.6A9 9 0 0 1 20.4 9H15z"/>',
    eye:         '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
    calendar:    '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
    sparkle:     '<path d="M12 3l1.9 5.6L19.5 10l-5.6 1.9L12 17.5 10.1 12 4.5 10l5.6-1.4z"/><path d="M18.5 16.5l.7 2 2 .7-2 .7-.7 2-.7-2-2-.7 2-.7z"/>',
    globe:       '<circle cx="12" cy="12" r="9"/><path d="M3.5 9h17M3.5 15h17"/><path d="M12 3c2.5 2.4 3.8 5.6 3.8 9S14.5 18.6 12 21c-2.5-2.4-3.8-5.6-3.8-9S9.5 5.4 12 3z"/>',

    /* misc */
    mail:        '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="m3.5 7 8.5 6 8.5-6"/>',
    phone:       '<path d="M6.5 3h3l1.5 4.5-2 1.4a12 12 0 0 0 6.1 6.1l1.4-2L21 14.5v3a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 4.5 5.2 2 2 0 0 1 6.5 3z"/>',
    pin:         '<path d="M12 21s7-6.3 7-11a7 7 0 1 0-14 0c0 4.7 7 11 7 11z"/><circle cx="12" cy="10" r="2.6"/>',
    edit:        '<path d="M4 20h4L20 8l-4-4L4 16z"/><path d="m14.5 5.5 4 4"/>',
    trash:       '<path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/><path d="M6 7v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V7M10 11v6M14 11v6"/>',
    sun:         '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    moon:        '<path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5z"/>'
  };

  function svg(name, cls) {
    var d = P[name];
    if (!d) { d = P.info; }
    return '<svg class="' + (cls || 'ico') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
           'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' + d + '</svg>';
  }

  /* Replace every <i data-ico="name"> with a real inline <svg>.
     Decorative by default (aria-hidden). Add data-label="…" when the icon
     carries meaning that no adjacent text provides. */
  function mount(root) {
    var nodes = (root || document).querySelectorAll('i[data-ico]');
    Array.prototype.forEach.call(nodes, function (n) {
      var name = n.getAttribute('data-ico');
      var label = n.getAttribute('data-label');
      var cls = 'ico ' + (n.className || '');
      var el = document.createElement('span');
      el.innerHTML = svg(name, cls.trim());
      var s = el.firstChild;
      if (label) {
        s.setAttribute('role', 'img');
        s.setAttribute('aria-label', label);
      } else {
        s.setAttribute('aria-hidden', 'true');
        s.setAttribute('focusable', 'false');
      }
      n.parentNode.replaceChild(s, n);
    });
  }

  global.Icons = { svg: svg, mount: mount, names: Object.keys(P) };
})(window);
