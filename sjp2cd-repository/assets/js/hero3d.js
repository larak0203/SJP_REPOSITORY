/* =============================================================================
   Ambient hero background.

   A handful of large, soft, low-poly forms drifting behind the headline. It is
   atmosphere, not a graphic: low contrast, no cursor interaction, no scroll
   triggers, and no loop that visibly restarts.

   Everything here is an ENHANCEMENT. The hero already paints a static gradient
   from CSS, so if this file never loads, never runs, or bails out at any of the
   checks below, the page is still complete and still on-brand.

   It refuses to run when:
     · the reader prefers reduced motion
     · the viewport is under 768px (phones keep the gradient)
     · WebGL is unavailable
     · the device reports very low memory or few cores

   It stops rendering when the hero scrolls away or the tab is hidden, and caps
   itself at 30fps — this is background texture, not a game.
   ========================================================================== */
(function () {
  'use strict';

  var CAP_FPS   = 30;
  var MIN_WIDTH = 768;
  var SRC       = 'assets/js/vendor/three.min.js';

  var hero   = document.getElementById('hero');
  var canvas = hero && hero.querySelector('[data-hero-canvas]');
  if (!hero || !canvas) return;

  /* ---------------------------------------------------------- gate checks */

  function allowed() {
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return false;
    if (window.innerWidth < MIN_WIDTH) return false;
    if (navigator.deviceMemory && navigator.deviceMemory < 4) return false;
    if (navigator.hardwareConcurrency && navigator.hardwareConcurrency < 4) return false;
    try {
      var probe = document.createElement('canvas');
      var gl = probe.getContext('webgl') || probe.getContext('experimental-webgl');
      if (!gl) return false;
      var lose = gl.getExtension('WEBGL_lose_context');
      if (lose) lose.loseContext();
    } catch (e) { return false; }
    return true;
  }

  /* Resolve the script relative to this file, so it works whatever BASE_URL is. */
  function vendorUrl() {
    var here = document.currentScript && document.currentScript.src;
    if (!here) {
      var tags = document.getElementsByTagName('script');
      for (var i = tags.length - 1; i >= 0; i--) {
        if (tags[i].src && tags[i].src.indexOf('hero3d.js') !== -1) { here = tags[i].src; break; }
      }
    }
    if (!here) return SRC;
    return here.replace(/\/hero3d\.js.*$/, '/vendor/three.min.js');
  }

  function loadThree() {
    return new Promise(function (resolve, reject) {
      if (window.THREE) return resolve(window.THREE);
      var el = document.createElement('script');
      el.src = vendorUrl();
      el.async = true;
      el.onload  = function () { window.THREE ? resolve(window.THREE) : reject(new Error('THREE missing')); };
      el.onerror = function () { reject(new Error('three.min.js failed to load')); };
      document.head.appendChild(el);
    });
  }

  /* ------------------------------------------------------------ the scene */

  function build(THREE) {
    var renderer = new THREE.WebGLRenderer({
      canvas: canvas,
      alpha: true,
      antialias: false,          /* soft blurred forms — AA buys nothing here */
      powerPreference: 'low-power'
    });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));

    var scene  = new THREE.Scene();
    var camera = new THREE.PerspectiveCamera(48, 1, 0.1, 100);
    camera.position.set(0, 0, 16);

    /* Fog matches the page ground so the forms dissolve into it rather than
       ending at a hard edge. Both values are the actual --bg of each theme. */
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    scene.fog = new THREE.FogExp2(dark ? 0x04050f : 0xf4f6fc, 0.055);

    /* Palette stays inside the dark navy scheme: deep indigo, violet, and a
       single yellow form used only as a faint accent. */
    var palette = [0x272baf, 0x4c56e8, 0x5b4396, 0x1a1d92, 0x6266dc];

    var forms = [];
    var COUNT = 6;

    for (var i = 0; i < COUNT; i++) {
      /* Detail 1 keeps the facets large and readable as soft planes. */
      var geo = new THREE.IcosahedronGeometry(1, 1);

      /* A gentle per-vertex displacement so no two forms are identical and none
         reads as a plain sphere. Baked once — not animated, which keeps the
         per-frame cost to a transform. */
      var pos = geo.attributes.position;
      for (var v = 0; v < pos.count; v++) {
        var n = 1 + (Math.sin(v * 12.9898 + i * 4.1414) * 0.5 + 0.5) * 0.22;
        pos.setXYZ(v, pos.getX(v) * n, pos.getY(v) * n, pos.getZ(v) * n);
      }
      geo.computeVertexNormals();

      var accent = (i === COUNT - 1);
      var mat = new THREE.MeshStandardMaterial({
        color: accent ? 0xffe44d : palette[i % palette.length],
        roughness: 0.85,
        metalness: 0.05,
        flatShading: true,
        transparent: true,
        /* Far fainter on light: a shape that reads as atmosphere on black
           reads as a stain on white. */
        opacity: accent ? (dark ? 0.16 : 0.10) : (dark ? 0.34 : 0.16)
      });

      var mesh = new THREE.Mesh(geo, mat);
      var scale = 2.6 + (i % 3) * 1.5;
      mesh.scale.setScalar(accent ? 1.8 : scale);
      mesh.position.set(
        -9 + (i * 3.6) % 18,
        -4 + ((i * 2.7) % 8),
        -6 - (i % 4) * 2.5
      );

      /* Each form gets its own irrational-ish rates, so the composition never
         returns to a previous state — there is no visible loop point. */
      mesh.userData = {
        rx: 0.013 + i * 0.0041,
        ry: 0.017 + i * 0.0033,
        fx: 0.11 + i * 0.037,
        fy: 0.09 + i * 0.029,
        ax: 0.9 + (i % 3) * 0.35,
        ay: 0.7 + (i % 4) * 0.3,
        ox: mesh.position.x,
        oy: mesh.position.y
      };

      scene.add(mesh);
      forms.push(mesh);
    }

    scene.add(new THREE.AmbientLight(0x8f97ff, dark ? 0.55 : 0.85));

    var key = new THREE.DirectionalLight(0xa8b0ff, dark ? 0.7 : 0.45);
    key.position.set(-6, 8, 10);
    scene.add(key);

    /* The yellow rim light — the only place the seal colour appears in the
       animation, and deliberately faint. */
    var rim = new THREE.PointLight(0xffe44d, dark ? 0.5 : 0.28, 40);
    rim.position.set(9, -5, 6);
    scene.add(rim);

    function resize() {
      var w = hero.clientWidth || window.innerWidth;
      var h = hero.clientHeight || window.innerHeight;
      renderer.setSize(w, h, false);
      camera.aspect = w / h;
      camera.updateProjectionMatrix();
    }
    resize();

    return {
      renderer: renderer, scene: scene, camera: camera, forms: forms, resize: resize,
      dispose: function () {
        forms.forEach(function (m) { m.geometry.dispose(); m.material.dispose(); });
        renderer.dispose();
      }
    };
  }

  /* ----------------------------------------------------------- the driver */

  var THREE_REF = null;

  function start(world) {
    var frame = null;
    var last = 0;
    var interval = 1000 / CAP_FPS;
    var onScreen = true;
    var running = false;
    /* Time is accumulated only while visible, so pausing never causes a jump
       when rendering resumes. */
    var clock = 0;

    function tick(now) {
      frame = requestAnimationFrame(tick);
      var delta = now - last;
      if (delta < interval) return;
      last = now - (delta % interval);

      clock += delta * 0.001;

      for (var i = 0; i < world.forms.length; i++) {
        var m = world.forms[i], d = m.userData;
        m.rotation.x += d.rx * 0.02;
        m.rotation.y += d.ry * 0.02;
        m.position.x = d.ox + Math.sin(clock * d.fx) * d.ax;
        m.position.y = d.oy + Math.cos(clock * d.fy) * d.ay;
      }

      world.renderer.render(world.scene, world.camera);
    }

    function run() {
      if (running || !onScreen || document.hidden) return;
      running = true;
      last = performance.now();
      frame = requestAnimationFrame(tick);
    }
    function stop() {
      running = false;
      if (frame) { cancelAnimationFrame(frame); frame = null; }
    }

    /* Stop the moment the hero leaves the screen — the rest of the page should
       never pay for a background it cannot show. */
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        onScreen = entries[0].isIntersecting;
        onScreen ? run() : stop();
      }, { threshold: 0.01 }).observe(hero);
    }

    document.addEventListener('visibilitychange', function () {
      document.hidden ? stop() : run();
    });

    /* The toggle changes the ground the scene sits on, so the scene is rebuilt
       rather than left in the previous theme's colours. */
    new MutationObserver(function () {
      stop();
      world.dispose();
      canvas.classList.remove('is-live');
      world = build(THREE_REF);
      world.renderer.render(world.scene, world.camera);
      canvas.classList.add('is-live');
      run();
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

    var resizeTimer = null;
    window.addEventListener('resize', function () {
      clearTimeout(resizeTimer);
      resizeTimer = setTimeout(function () {
        /* If the window shrinks to phone width, hand back to the gradient. */
        if (window.innerWidth < MIN_WIDTH) { stop(); canvas.classList.remove('is-live'); return; }
        world.resize();
        canvas.classList.add('is-live');
        run();
      }, 200);
    });

    /* Draw one frame before revealing, so the canvas never fades in empty. */
    world.renderer.render(world.scene, world.camera);
    canvas.classList.add('is-live');
    run();
  }

  /* ------------------------------------------------------------ bootstrap */

  function begin() {
    if (!allowed()) return;              /* gradient stays; nothing else happens */
    loadThree()
      .then(function (THREE) { THREE_REF = THREE; start(build(THREE)); })
      .catch(function () {
        /* Offline, blocked, or WebGL died. The gradient is already on screen,
           so there is nothing to clean up and nothing to tell the reader. */
        canvas.classList.remove('is-live');
      });
  }

  /* Deferred past first paint: the 590 KB library must never delay the
     headline, the search box, or anything the reader came for. */
  if (document.readyState === 'complete') {
    ('requestIdleCallback' in window) ? requestIdleCallback(begin, { timeout: 2500 }) : setTimeout(begin, 400);
  } else {
    window.addEventListener('load', function () {
      ('requestIdleCallback' in window) ? requestIdleCallback(begin, { timeout: 2500 }) : setTimeout(begin, 400);
    });
  }
})();
