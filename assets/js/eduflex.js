/* ==========================================================================
   EduFlex — front-end behaviour
   jQuery is used because the manuscript's technology stack declares it.
   Everything here is presentation only. No business logic lives in the client.
   ========================================================================== */

(function ($) {
  'use strict';

  /* ------------------------------------------------------------------
     Mobile navigation drawer
     The Android build is a WebView around these pages, so the drawer is
     what the mobile storyboard frames show.
     ------------------------------------------------------------------ */
  function initDrawer() {
    var $sidebar = $('.ef-sidebar');
    if (!$sidebar.length) return;

    if (!$('.ef-scrim').length) {
      $('body').append('<div class="ef-scrim"></div>');
    }
    var $scrim = $('.ef-scrim');

    function open() { $sidebar.addClass('is-open'); $scrim.addClass('is-open'); }
    function close() { $sidebar.removeClass('is-open'); $scrim.removeClass('is-open'); }

    $(document).on('click', '.ef-burger', function (e) {
      e.preventDefault();
      $sidebar.hasClass('is-open') ? close() : open();
    });
    $(document).on('click', '.ef-scrim', close);
    $(document).on('keydown', function (e) { if (e.key === 'Escape') close(); });
    $(window).on('resize', function () { if (window.innerWidth > 860) close(); });
  }

  /* ------------------------------------------------------------------
     Mastery band helper

     This mirrors the model documented in Chapter III. Keep the thresholds
     here identical to the server-side calculation, or the UI will disagree
     with the database.

       band(pct, itemCount):
         itemCount < MIN_ITEMS -> 'none'   (not enough evidence yet)
         pct >= 85             -> 'mastered'
         pct >= 70             -> 'developing'
         otherwise             -> 'weak'
     ------------------------------------------------------------------ */
  var MIN_ITEMS = 5;

  function masteryBand(pct, itemCount) {
    if (typeof itemCount === 'number' && itemCount < MIN_ITEMS) return 'none';
    if (pct === null || typeof pct === 'undefined') return 'none';
    if (pct >= 85) return 'mastered';
    if (pct >= 70) return 'developing';
    return 'weak';
  }

  function bandLabel(band) {
    return { mastered: 'Mastered', developing: 'Developing', weak: 'Weak', none: 'No data' }[band];
  }

  /* Render any element marked up as:
     <div class="ef-mastery-row" data-topic="Fourier Transforms"
          data-mastery="58" data-items="12"></div>                     */
  function renderMasteryRows() {
    $('.ef-mastery-row').each(function () {
      var $el = $(this);
      if ($el.data('rendered')) return;

      var topic = $el.data('topic');
      var items = parseInt($el.data('items'), 10);
      var raw = $el.attr('data-mastery');
      var pct = (raw === '' || raw === null || typeof raw === 'undefined') ? null : parseFloat(raw);

      var band = masteryBand(pct, items);
      var showPct = band !== 'none' && pct !== null;

      var html =
        '<div class="ef-mastery-row-top">' +
          '<span class="ef-mastery-name">' + topic + '</span>' +
          '<span class="ef-mastery-right">' +
            '<span class="ef-band ef-band-' + band + '">' + bandLabel(band) + '</span>' +
            '<span class="ef-mastery-val" style="color:' +
              (showPct ? 'var(--ef-' + band + ')' : 'var(--ef-text-muted)') + '">' +
              (showPct ? Math.round(pct) + '%' : '—') +
            '</span>' +
          '</span>' +
        '</div>' +
        '<div class="ef-track">' +
          (showPct ? '<span class="ef-fill-' + band + '" style="width:' + pct + '%"></span>' : '') +
        '</div>';

      $el.html(html).data('rendered', true);
    });
  }

  /* ------------------------------------------------------------------
     Bar chart heights from data attributes
     <div class="ef-bars" data-max="5"> <div class="ef-bar-col" data-value="4">
     ------------------------------------------------------------------ */
  function renderBars() {
    $('.ef-bars').each(function () {
      var $chart = $(this);
      var max = parseFloat($chart.data('max')) || 1;
      var trackH = $chart.height() - 32; /* leave room for the day label */

      $chart.find('.ef-bar-col').each(function () {
        var v = parseFloat($(this).data('value')) || 0;
        var $bar = $(this).find('.ef-bar');
        var h = v === 0 ? 6 : Math.round((v / max) * trackH);
        $bar.css('height', h + 'px');
        $bar.toggleClass('is-zero', v === 0);
        $bar.toggleClass('is-peak', v >= max);
      });
    });
  }

  /* ------------------------------------------------------------------
     Quiz option selection (Practice screen, presentation only)
     ------------------------------------------------------------------ */
  function initQuizOptions() {
    $(document).on('click', '.ef-quiz-option', function () {
      $(this).closest('.ef-quiz-options').find('.ef-quiz-option').removeClass('is-selected');
      $(this).addClass('is-selected');
    });
  }

  /* ------------------------------------------------------------------
     Landing page: the robot watches the cursor

     Only runs on index.php, where [data-bot] exists. Everything it does is
     decoration, so it is written to be skippable: the illustration still
     breathes and blinks without this, because that motion is pure CSS.

     Two things it must respect:

       prefers-reduced-motion. Somebody who has asked their system for less
       movement should not get an illustration that follows them around the
       screen. The stylesheet stops the loops; this stops the tracking.

       The frame budget. mousemove fires far faster than the screen refreshes,
       so the handler only records the position and the real work happens once
       per frame in requestAnimationFrame.
     ------------------------------------------------------------------ */
  function initLanding() {
    var $visual = $('[data-hero-visual]');
    var bot = document.querySelector('[data-bot]');
    if (!$visual.length || !bot) return;

    var reduced = window.matchMedia &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* A press gives one nod. Worth having even with reduced motion off the
       table, because it is a response to a deliberate action rather than
       something that moves on its own. */
    $visual.on('click', function () {
      if (reduced || bot.classList.contains('is-nodding')) return;
      bot.classList.add('is-nodding');
      window.setTimeout(function () { bot.classList.remove('is-nodding'); }, 520);
    });

    if (reduced) return;

    var $floats = $visual.find('[data-float]');
    var pointer = null;
    var queued = false;

    /* How far each part may travel, in SVG user units for the robot and in
       pixels for the chips. Kept small: this is a glance, not a puppet. */
    var EYE_RANGE = 7;
    var HEAD_RANGE = 9;

    function apply() {
      queued = false;
      if (!pointer) return;

      var box = $visual[0].getBoundingClientRect();
      if (!box.width || !box.height) return;

      /* Position relative to the middle of the illustration, as -1 to 1. */
      var dx = (pointer.x - (box.left + box.width / 2)) / (box.width / 2);
      var dy = (pointer.y - (box.top + box.height / 2)) / (box.height / 2);

      dx = Math.max(-1, Math.min(1, dx));
      dy = Math.max(-1, Math.min(1, dy));

      bot.style.setProperty('--ef-eye-x', (dx * EYE_RANGE).toFixed(2) + 'px');
      bot.style.setProperty('--ef-eye-y', (dy * EYE_RANGE * 0.7).toFixed(2) + 'px');
      bot.style.setProperty('--ef-head-x', (dx * HEAD_RANGE).toFixed(2) + 'px');
      bot.style.setProperty('--ef-head-y', (dy * HEAD_RANGE * 0.5).toFixed(2) + 'px');

      /* The chips drift the other way from the cursor, by an amount set per
         chip in data-depth, which is what gives the group its sense of depth.
         A negative depth moves with the cursor instead of against it. */
      $floats.each(function () {
        var depth = parseFloat($(this).attr('data-depth')) || 0;
        this.style.setProperty('--ef-parallax-x', (-dx * depth).toFixed(2) + 'px');
        this.style.setProperty('--ef-parallax-y', (-dy * depth * 0.6).toFixed(2) + 'px');
      });
    }

    function schedule() {
      if (queued) return;
      queued = true;
      window.requestAnimationFrame(apply);
    }

    /* Tracked across the whole window rather than the illustration alone, so
       the robot notices the cursor before it arrives. */
    $(window).on('mousemove.efLanding', function (e) {
      pointer = { x: e.clientX, y: e.clientY };
      schedule();
    });

    /* Back to centre when the pointer leaves the window, so it does not freeze
       mid-glance at whatever edge the cursor left by. */
    $(document).on('mouseleave.efLanding', function () {
      pointer = null;
      bot.style.setProperty('--ef-eye-x', '0px');
      bot.style.setProperty('--ef-eye-y', '0px');
      bot.style.setProperty('--ef-head-x', '0px');
      bot.style.setProperty('--ef-head-y', '0px');
      $floats.each(function () {
        this.style.setProperty('--ef-parallax-x', '0px');
        this.style.setProperty('--ef-parallax-y', '0px');
      });
    });

    /* A touch screen has no hovering cursor, so a tap is the whole
       interaction. Passive: this never calls preventDefault, and saying so
       keeps scrolling smooth. */
    $visual[0].addEventListener('touchstart', function (e) {
      if (!e.touches || !e.touches.length) return;
      pointer = { x: e.touches[0].clientX, y: e.touches[0].clientY };
      schedule();
    }, { passive: true });
  }

  /* ------------------------------------------------------------------
     The explainer video

     Both "See How it Works" in the hero and the poster in the How it Works
     section open one <dialog> holding one <video>.

     Three things here are not decoration:

       Closing must stop playback. A <dialog> that is closed is only hidden,
       so without this the narration carries on from an invisible element and
       the visitor cannot find what is talking to them. It rewinds too, so the
       next open starts at the beginning rather than halfway through.

       The hero trigger is an <a href="#how">, so its default has to be
       prevented, and only when the dialog is genuinely usable. If <dialog> is
       missing we leave the click alone and the browser scrolls to the section,
       which is a worse experience but not a broken one.

       play() returns a promise that rejects if the browser declines, which it
       may do on a policy this code cannot see. It is caught and ignored: the
       controls are right there, and an unhandled rejection in the console is
       the kind of thing that gets noticed during a defense.
     ------------------------------------------------------------------ */
  function initVideoDialog() {
    var dialog = document.querySelector('[data-video-dialog]');
    if (!dialog || typeof dialog.showModal !== 'function') return;

    var video = dialog.querySelector('[data-video]');

    function open(event) {
      if (event) event.preventDefault();
      dialog.showModal();
      document.documentElement.classList.add('ef-no-scroll');
      if (video && video.play) {
        var p = video.play();
        if (p && p.catch) p.catch(function () {});
      }
    }

    function close() {
      if (dialog.open) dialog.close();
    }

    $(document).on('click', '[data-video-open]', open);
    $(dialog).on('click', '[data-video-close]', close);

    /* The dialog element itself is the full-screen backdrop, so a click that
       lands on it rather than on the panel inside is a click outside. */
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) close();
    });

    /* Fires for the close button, for Escape, and for form method="dialog".
       Hanging the reset here means every route out is covered by one handler. */
    dialog.addEventListener('close', function () {
      document.documentElement.classList.remove('ef-no-scroll');
      if (!video) return;
      video.pause();
      try { video.currentTime = 0; } catch (e) {}
    });
  }

  /* ------------------------------------------------------------------
     Show / hide password

     Three fields: one on login, two on registration. Delegated from the
     document so one handler covers all of them and any added later.

     Two details that are easy to get wrong:

       Changing input.type moves the caret to the end in most engines, which
       is maddening halfway through typing a long password. The selection is
       read before the swap and restored after it.

       Which icon shows is a CSS class, not a style set from here. If this
       script never loads, the button simply sits there showing a closed eye
       rather than showing both icons at once.

     The button is type="button" in password_toggle_html(), so it cannot submit
     the form it lives in. That is asserted in tests/auth_test.php.
     ------------------------------------------------------------------ */
  function initPasswordToggles() {
    $(document).on('click', '[data-password-toggle]', function () {
      var $button = $(this);
      var input = $button.closest('.ef-password').find('input')[0];
      if (!input) return;

      var reveal = input.type === 'password';
      var start = null;
      var end = null;
      try { start = input.selectionStart; end = input.selectionEnd; } catch (e) {}

      input.type = reveal ? 'text' : 'password';

      $button
        .toggleClass('is-revealed', reveal)
        .attr('aria-pressed', reveal ? 'true' : 'false')
        .attr('aria-label', reveal ? 'Hide password' : 'Show password')
        .attr('title', reveal ? 'Hide password' : 'Show password');

      // Firefox throws on setSelectionRange for some input types, and a lost
      // caret is not worth an exception.
      input.focus();
      if (start !== null) {
        try { input.setSelectionRange(start, end); } catch (e) {}
      }
    });
  }

  /* ---- The AI background on the sign-in and sign-up pages ----------------
     Added 20 November 2026. Markup: partials/ai_background.php. Styles:
     section 18 of eduflex.css.

     Draws a living neural network on one canvas:
       - free nodes drifting at three depths, linking when they come within
         reach of each other and unlinking as they part
       - a brain-shaped cluster beside the card whose nodes breathe in place
         and whose mesh glows as it "thinks"
       - signals: points of light that run along a link and, more often than
         not, hop on to a neighbour, so activity travels through the network
       - a few long light streaks crossing the screen
       - the pointer: parallax for every layer, and faint links from the
         cursor to the nodes around it

     Cost control: the canvas resolution is capped at 1.5x, the node count
     scales with the screen and is lower on phones, links are only tested
     within a fixed reach, and requestAnimationFrame stops on its own when
     the tab is hidden. With prefers-reduced-motion it draws one still frame
     and never loops. */
  function initAiBackground() {
    var bg = document.querySelector('.ef-ai-bg');
    var canvas = bg ? bg.querySelector('.ef-ai-canvas') : null;
    if (!canvas || !canvas.getContext) { return; }
    var ctx = canvas.getContext('2d');
    if (!ctx) { return; }

    var still = window.matchMedia &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var SKY = '56, 189, 248', CYAN = '34, 211, 238', BLUE = '37, 99, 235';
    var W = 0, H = 0, small = false, reach = 130;
    var nodes = [], brain = [], streaks = [], pulses = [], links = [];
    var brainBox = { x: 0, y: 0, r: 0, alpha: 1 };
    var pointer = { x: 0, y: 0, tx: 0, ty: 0, px: -9999, py: -9999 };
    var started = 0, last = 0, nextPulse = 0;

    /* A fixed seed for the brain, so it is the same shape on every visit. */
    var seed = 20261120;
    function seeded() { seed = (seed * 16807) % 2147483647; return (seed - 1) / 2147483646; }

    /* The landing hero turns the brain off (data-brain="off"); see
       partials/ai_background.php for why. */
    var withBrain = bg.getAttribute('data-brain') !== 'off';
    /* Without the brain the free network carries the scene alone, so it is
       denser and brighter there. */
    var boost = withBrain ? 1 : 1.45;

    function build() {
      var dpr = Math.min(window.devicePixelRatio || 1, 1.5);
      /* The layer's own box, not the window: on the sign-in pages they are
         the same, but on the landing page the layer only fills the hero. */
      W = Math.max(1, bg.clientWidth || window.innerWidth);
      H = Math.max(1, bg.clientHeight || window.innerHeight);
      small = W < 760;
      reach = small ? 100 : 135;
      canvas.width = Math.round(W * dpr);
      canvas.height = Math.round(H * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

      /* Free nodes. */
      var count = Math.round(Math.max(22, Math.min(small ? 34 : 72, boost * (W * H) / 17000)));
      nodes = [];
      for (var i = 0; i < count; i++) {
        var depth = 0.35 + Math.random() * 0.65;
        nodes.push({
          x: Math.random() * W, y: Math.random() * H,
          vx: (Math.random() - 0.5) * 0.26 * depth,
          vy: (Math.random() - 0.5) * 0.26 * depth,
          r: 0.9 + depth * 1.7, depth: depth,
          phase: Math.random() * 6.283, flash: 0, dx: 0, dy: 0
        });
      }

      /* The brain. Beside the card on a wide screen, above it on a narrow one,
         where it sits fainter so the form stays the focus. */
      var size;
      if (W >= 1080) {
        size = Math.min(340, (W - 420) / 2 - 70);
        brainBox = { x: (W - 420) / 4, y: H * 0.5, r: size / 2, alpha: 1 };
      } else {
        size = Math.min(280, W * 0.62);
        brainBox = { x: W / 2, y: Math.max(130, H * 0.17), r: size / 2, alpha: small ? 0.45 : 0.6 };
      }

      seed = 20261120;
      brain = [];
      var rx = brainBox.r, ry = brainBox.r * 0.72;
      var want = small ? 34 : 56, guard = 0;
      while (brain.length < want && guard++ < 4000) {
        var onEdge = brain.length < want * 0.55;
        var a = seeded() * 6.283, px, py;
        if (onEdge) {
          /* The cerebrum's outline, rippled like folds. */
          var k = 1 + 0.07 * Math.sin(a * 9);
          px = Math.cos(a) * rx * k;
          py = Math.sin(a) * ry * k;
          /* Leave the lower back open for the cerebellum. */
          if (px > rx * 0.25 && py > ry * 0.35) { continue; }
        } else {
          var u = Math.sqrt(seeded());
          px = Math.cos(a) * rx * 0.92 * u;
          py = Math.sin(a) * ry * 0.92 * u;
        }
        brain.push(brainNode(px, py));
      }
      /* Cerebellum and stem, so it reads as a brain and not as an egg. */
      for (var c = 0; c < (small ? 5 : 8); c++) {
        var b = seeded() * 6.283, v = 0.4 + seeded() * 0.6;
        brain.push(brainNode(rx * 0.52 + Math.cos(b) * rx * 0.3 * v,
                             ry * 0.72 + Math.sin(b) * ry * 0.26 * v));
      }
      brain.push(brainNode(rx * 0.12, ry * 1.05));
      brain.push(brainNode(rx * 0.18, ry * 1.3));
      if (!withBrain) { brain = []; brainBox.alpha = 0; }

      /* Light streaks. */
      streaks = [];
      for (var s = 0; s < (small ? 2 : 4); s++) { streaks.push(newStreak(true)); }

      pulses = [];
    }

    function brainNode(ox, oy) {
      return {
        ox: ox, oy: oy, x: 0, y: 0, r: 1.7 + seeded() * 1.5,
        phase: seeded() * 6.283, flash: 0, depth: 0.75, brain: true, dx: 0, dy: 0
      };
    }

    function newStreak(anywhere) {
      var len = 120 + Math.random() * 160;
      return {
        x: anywhere ? Math.random() * W : -len - Math.random() * W * 0.5,
        y: Math.random() * H,
        len: len,
        speed: 1.2 + Math.random() * 1.6,
        slope: -0.18 + Math.random() * 0.12,
        alpha: 0.18 + Math.random() * 0.22
      };
    }

    function spawnPulse(fromBrain) {
      var pool = links.filter(function (l) { return fromBrain ? l.inBrain : !l.inBrain; });
      if (!pool.length) { pool = links; }
      if (!pool.length) { return; }
      var l = pool[(Math.random() * pool.length) | 0];
      var flip = Math.random() < 0.5;
      pulses.push({ a: flip ? l.b : l.a, b: flip ? l.a : l.b, p: 0,
                    speed: 0.012 + Math.random() * 0.018, hops: 0 });
    }

    function step(dt, t) {
      var px = pointer.x, py = pointer.y, i, n;

      for (i = 0; i < nodes.length; i++) {
        n = nodes[i];
        n.x += n.vx * dt;
        n.y += n.vy * dt;
        if (n.x < -40) { n.x = W + 40; } else if (n.x > W + 40) { n.x = -40; }
        if (n.y < -40) { n.y = H + 40; } else if (n.y > H + 40) { n.y = -40; }
        n.dx = n.x + px * 22 * n.depth;
        n.dy = n.y + py * 16 * n.depth;
        n.flash *= 0.94;
      }

      var bx = brainBox.x + px * 12, by = brainBox.y + py * 9;
      for (i = 0; i < brain.length; i++) {
        n = brain[i];
        /* Each node breathes around its own anchor. */
        n.dx = bx + n.ox + Math.sin(t * 0.7 + n.phase) * 3.2;
        n.dy = by + n.oy + Math.cos(t * 0.6 + n.phase * 1.3) * 3.2;
        n.flash *= 0.94;
      }

      for (i = 0; i < streaks.length; i++) {
        var s = streaks[i];
        s.x += s.speed * dt;
        s.y += s.speed * s.slope * dt;
        if (s.x - s.len > W + 40) { streaks[i] = newStreak(false); }
      }
    }

    function draw(t, intro) {
      ctx.clearRect(0, 0, W, H);
      ctx.globalAlpha = intro;
      ctx.lineCap = 'round';
      links = [];

      var i, j, a, b, d, dx, dy, alpha;
      var reach2 = reach * reach;

      /* Light streaks, under everything else. */
      for (i = 0; i < streaks.length; i++) {
        var s = streaks[i];
        var x2 = s.x - s.len, y2 = s.y - s.len * s.slope;
        var g = ctx.createLinearGradient(s.x, s.y, x2, y2);
        g.addColorStop(0, 'rgba(' + CYAN + ',' + s.alpha + ')');
        g.addColorStop(1, 'rgba(' + CYAN + ',0)');
        ctx.strokeStyle = g;
        ctx.lineWidth = 1.4;
        ctx.beginPath(); ctx.moveTo(s.x, s.y); ctx.lineTo(x2, y2); ctx.stroke();
      }

      /* The brain's glow, breathing. */
      if (withBrain) {
        var bx = brainBox.x + pointer.x * 12, by = brainBox.y + pointer.y * 9;
        var glow = ctx.createRadialGradient(bx, by, 0, bx, by, brainBox.r * 1.5);
        var breathe = 0.2 + Math.sin(t * 1.1) * 0.05;
        glow.addColorStop(0, 'rgba(' + CYAN + ',' + (breathe * brainBox.alpha) + ')');
        glow.addColorStop(1, 'rgba(' + CYAN + ',0)');
        ctx.fillStyle = glow;
        ctx.fillRect(bx - brainBox.r * 1.6, by - brainBox.r * 1.6, brainBox.r * 3.2, brainBox.r * 3.2);
      }

      /* Free links: connect and disconnect as nodes drift. */
      ctx.lineWidth = 1;
      for (i = 0; i < nodes.length; i++) {
        a = nodes[i];
        for (j = i + 1; j < nodes.length; j++) {
          b = nodes[j];
          dx = a.dx - b.dx; dy = a.dy - b.dy; d = dx * dx + dy * dy;
          if (d < reach2) {
            alpha = (1 - Math.sqrt(d) / reach) * 0.32 * boost * Math.min(a.depth, b.depth);
            ctx.strokeStyle = 'rgba(' + SKY + ',' + alpha.toFixed(3) + ')';
            ctx.beginPath(); ctx.moveTo(a.dx, a.dy); ctx.lineTo(b.dx, b.dy); ctx.stroke();
            links.push({ a: a, b: b, inBrain: false });
          }
        }
        /* Free nodes that wander near the brain join it for a while. */
        for (j = 0; j < brain.length; j += 2) {
          b = brain[j];
          dx = a.dx - b.dx; dy = a.dy - b.dy; d = dx * dx + dy * dy;
          if (d < reach2 * 0.6) {
            alpha = (1 - Math.sqrt(d) / (reach * 0.77)) * 0.22 * brainBox.alpha;
            ctx.strokeStyle = 'rgba(' + SKY + ',' + alpha.toFixed(3) + ')';
            ctx.beginPath(); ctx.moveTo(a.dx, a.dy); ctx.lineTo(b.dx, b.dy); ctx.stroke();
            links.push({ a: a, b: b, inBrain: false });
          }
        }
      }

      /* The brain's own mesh, brighter, pulsing as a whole. */
      var meshReach = brainBox.r * 0.48, mesh2 = meshReach * meshReach;
      var think = 0.85 + Math.sin(t * 1.6) * 0.15;
      for (i = 0; i < brain.length; i++) {
        a = brain[i];
        for (j = i + 1; j < brain.length; j++) {
          b = brain[j];
          dx = a.dx - b.dx; dy = a.dy - b.dy; d = dx * dx + dy * dy;
          if (d < mesh2) {
            alpha = (1 - Math.sqrt(d) / meshReach) * 0.62 * think * brainBox.alpha;
            ctx.strokeStyle = 'rgba(' + CYAN + ',' + alpha.toFixed(3) + ')';
            ctx.lineWidth = 1.1;
            ctx.beginPath(); ctx.moveTo(a.dx, a.dy); ctx.lineTo(b.dx, b.dy); ctx.stroke();
            links.push({ a: a, b: b, inBrain: true });
          }
        }
      }

      /* The pointer reaches out to whatever is near it. */
      if (pointer.px > -9000) {
        var cr = reach * 1.15;
        for (i = 0; i < nodes.length; i++) {
          a = nodes[i];
          dx = a.dx - pointer.px; dy = a.dy - pointer.py; d = Math.sqrt(dx * dx + dy * dy);
          if (d < cr) {
            ctx.strokeStyle = 'rgba(' + BLUE + ',' + ((1 - d / cr) * 0.22).toFixed(3) + ')';
            ctx.lineWidth = 1;
            ctx.beginPath(); ctx.moveTo(a.dx, a.dy); ctx.lineTo(pointer.px, pointer.py); ctx.stroke();
          }
        }
      }

      /* Nodes. A node a signal just reached flares, then settles. */
      drawNodes(nodes, t, SKY, boost);
      drawNodes(brain, t, CYAN, brainBox.alpha);

      /* Signals. */
      for (i = pulses.length - 1; i >= 0; i--) {
        var p = pulses[i];
        var x = p.a.dx + (p.b.dx - p.a.dx) * p.p;
        var y = p.a.dy + (p.b.dy - p.a.dy) * p.p;
        var halo = ctx.createRadialGradient(x, y, 0, x, y, 9);
        halo.addColorStop(0, 'rgba(' + CYAN + ',.55)');
        halo.addColorStop(1, 'rgba(' + CYAN + ',0)');
        ctx.fillStyle = halo;
        ctx.beginPath(); ctx.arc(x, y, 9, 0, 6.283); ctx.fill();
        ctx.fillStyle = 'rgba(255,255,255,.95)';
        ctx.beginPath(); ctx.arc(x, y, 1.6, 0, 6.283); ctx.fill();
      }

      ctx.globalAlpha = 1;
    }

    function drawNodes(list, t, rgb, scale) {
      for (var i = 0; i < list.length; i++) {
        var n = list[i];
        var twinkle = 0.55 + Math.sin(t * 1.4 + n.phase) * 0.25;
        var a = Math.min(1, (twinkle * (n.depth + 0.25) + n.flash) * scale);
        if (n.flash > 0.05) {
          ctx.fillStyle = 'rgba(' + CYAN + ',' + (n.flash * 0.35 * scale).toFixed(3) + ')';
          ctx.beginPath(); ctx.arc(n.dx, n.dy, n.r + 6 * n.flash, 0, 6.283); ctx.fill();
        }
        ctx.fillStyle = 'rgba(' + rgb + ',' + a.toFixed(3) + ')';
        ctx.beginPath(); ctx.arc(n.dx, n.dy, n.r, 0, 6.283); ctx.fill();
      }
    }

    function advancePulses(dt, now) {
      if (now > nextPulse && pulses.length < (small ? 7 : 16)) {
        spawnPulse(Math.random() < 0.6);
        nextPulse = now + 160 + Math.random() * 260;
      }
      for (var i = pulses.length - 1; i >= 0; i--) {
        var p = pulses[i];
        p.p += p.speed * dt;
        if (p.p < 1) { continue; }
        p.b.flash = 1;
        /* Hop on to a neighbour, the way a signal propagates. */
        var next = null;
        if (p.hops < 5 && Math.random() < 0.7) {
          var options = links.filter(function (l) {
            return (l.a === p.b && l.b !== p.a) || (l.b === p.b && l.a !== p.a);
          });
          if (options.length) {
            var l = options[(Math.random() * options.length) | 0];
            next = l.a === p.b ? l.b : l.a;
          }
        }
        if (next) {
          p.a = p.b; p.b = next; p.p = 0; p.hops++;
        } else {
          pulses.splice(i, 1);
        }
      }
    }

    function frame(now) {
      if (!started) { started = now; last = now; }
      var dt = Math.min(3, (now - last) / 16.667);
      last = now;
      var t = now / 1000;
      var intro = Math.min(1, (now - started) / 1400);

      /* Ease the pointer, then hand it to the CSS layers. */
      pointer.x += (pointer.tx - pointer.x) * 0.05 * dt;
      pointer.y += (pointer.ty - pointer.y) * 0.05 * dt;
      bg.style.setProperty('--ef-ai-x', pointer.x.toFixed(3));
      bg.style.setProperty('--ef-ai-y', pointer.y.toFixed(3));

      step(dt, t);
      draw(t, intro);
      advancePulses(dt, now);
      if (onScreen) {
        window.requestAnimationFrame(frame);
      } else {
        running = false;
      }
    }

    /* Stop drawing while the layer is scrolled out of view. On the sign-in
       pages it never is; on the landing page this is what makes the rest of
       the page cost nothing once the reader scrolls past the hero. */
    var onScreen = true, running = false;
    function start() {
      if (running || still) { return; }
      running = true;
      last = 0; started = started || 0;
      window.requestAnimationFrame(function (now) { last = now; frame(now); });
    }

    build();

    var resizeTimer = 0;
    window.addEventListener('resize', function () {
      window.clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(function () {
        build();
        if (still) { step(0, 0); draw(0, 1); }
      }, 150);
    });

    if (still) {
      /* One still frame: the network and the brain, no movement. */
      step(0, 0);
      draw(0, 1);
      return;
    }

    /* Pointer position relative to the layer, so the landing hero's parallax
       and cursor links line up with the hero rather than the window. */
    window.addEventListener('pointermove', function (e) {
      var box = bg.getBoundingClientRect();
      var x = e.clientX - box.left, y = e.clientY - box.top;
      var inside = x >= 0 && y >= 0 && x <= box.width && y <= box.height;
      pointer.tx = Math.max(-1, Math.min(1, (x / W - 0.5) * 2));
      pointer.ty = Math.max(-1, Math.min(1, (y / H - 0.5) * 2));
      pointer.px = inside ? x : -9999;
      pointer.py = inside ? y : -9999;
    }, { passive: true });
    document.addEventListener('pointerleave', function () {
      pointer.tx = 0; pointer.ty = 0; pointer.px = -9999; pointer.py = -9999;
    });

    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (entries) {
        onScreen = entries[0].isIntersecting;
        if (onScreen) { start(); }
      }).observe(bg);
    }
    start();
  }

  $(function () {
    initDrawer();
    renderMasteryRows();
    renderBars();
    initQuizOptions();
    initLanding();
    initVideoDialog();
    initPasswordToggles();
    initAiBackground();
    initLoginWelcome();
    initSignOut();
    $(window).on('resize', renderBars);
  });

  /* A last "are you sure?" on any form carrying data-confirm.
     Added 19 November 2026 for Manage User Accounts, where Deactivate and
     Delete act on somebody else's account. It is a courtesy on top of the real
     protection, not a substitute for it: delete still requires the typed email,
     checked in PHP, so a learner with scripts off loses nothing. Delegated, so
     a form added after load is covered too. */
  $(document).on('submit', 'form[data-confirm]', function (e) {
    if (!window.confirm(String($(this).attr('data-confirm')))) {
      e.preventDefault();
    }
  });

  /* ---- Button motion (added 20 November 2026; CSS in section 17) ---------- */

  var reduceMotion = window.matchMedia &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* The ripple: a circle that grows from exactly where the button was pressed.
     pointerdown rather than click, so it starts the moment a finger lands and
     not after it lifts. Sized to cover the far corner from the press point. */
  $(document).on('pointerdown',
      '.ef-btn, .ef-chip, .ef-option, .ef-send, .ef-auth-tab, .ef-tab, .ef-rail-toggle',
      function (e) {
    if (reduceMotion || e.button > 0) { return; }
    var el = this;
    if (el.disabled || el.getAttribute('aria-disabled') === 'true') { return; }

    var rect = el.getBoundingClientRect();
    var x = e.clientX - rect.left;
    var y = e.clientY - rect.top;
    var r = Math.sqrt(Math.pow(Math.max(x, rect.width - x), 2) +
                      Math.pow(Math.max(y, rect.height - y), 2));

    var ripple = document.createElement('span');
    ripple.className = 'ef-ripple';
    ripple.setAttribute('aria-hidden', 'true');
    ripple.style.width = ripple.style.height = (r * 2) + 'px';
    ripple.style.left = (x - r) + 'px';
    ripple.style.top = (y - r) + 'px';
    /* First child, not last. A practice option styles its text with
       ".ef-option > span:last-child", and an appended ripple would take that
       place for 0.6s and make the text reflow. Nothing styles :first-child. */
    el.insertBefore(ripple, el.firstChild);

    ripple.addEventListener('animationend', function () { ripple.remove(); });
    // Belt and braces: if the animation never runs (a hidden tab), still tidy up.
    window.setTimeout(function () { if (ripple.parentNode) { ripple.remove(); } }, 1000);
  });

  /* Loading state on the button that submitted a form.

     Registered AFTER the data-confirm handler above, and both are delegated to
     the document, so this runs second and sees a cancelled confirm as
     isDefaultPrevented() and does nothing. The same check skips any form a
     page submits itself with AJAX.

     The button is NOT disabled. A disabled button is left out of the data the
     browser sends, so "Send and mark resolved" would arrive as a plain "Send
     reply". aria-disabled and pointer-events stop a second press instead.

     Opt out with data-no-loading on the form, for a form that downloads a file
     and so never leaves the page. The timeout is the fallback for any such
     form that forgets: a spinner that never stops is worse than none. */
  $(document).on('submit', 'form', function (e) {
    if (e.isDefaultPrevented() || this.hasAttribute('data-no-loading')) { return; }

    var submitter = (e.originalEvent && e.originalEvent.submitter) ||
                    $(this).find('button[type="submit"], button:not([type])').get(0);
    if (!submitter || !$(submitter).hasClass('ef-btn')) { return; }

    /* A button with data-loading-text says what is happening instead of
       going blank: the login button reads "Authenticating..." beside a
       spinner, with a soft pulse of glow. Added 20 November 2026. */
    var label = submitter.getAttribute('data-loading-text');
    if (label) {
      $(submitter).data('ef-original-html', submitter.innerHTML);
      $(submitter).addClass('is-authenticating')
        .attr({ 'aria-busy': 'true', 'aria-disabled': 'true' })
        .empty()
        .append('<span class="ef-btn-spinner" aria-hidden="true"></span>')
        .append(document.createTextNode(label));
    } else {
      $(submitter).addClass('is-loading').attr({ 'aria-busy': 'true', 'aria-disabled': 'true' });
    }
    window.setTimeout(function () { clearLoading(submitter); }, 12000);
  });

  function clearLoading(el) {
    var $el = $(el);
    var original = $el.data('ef-original-html');
    if (original !== undefined) {
      $el.html(original).removeData('ef-original-html');
    }
    $el.removeClass('is-loading is-authenticating').removeAttr('aria-busy aria-disabled');
  }

  /* Back-button restores from the browser's page cache keep whatever classes
     the page had when it was left, spinner included. Clear them on return. */
  $(window).on('pageshow', function (e) {
    if (e.originalEvent && e.originalEvent.persisted) {
      $('.ef-btn.is-loading, .ef-btn.is-authenticating').each(function () { clearLoading(this); });
    }
  });

  /* ---- The "Login Successful" welcome (20 November 2026) ------------------
     The sequence itself is pure CSS (section 19), so it plays even if this
     never runs. This adds: skip on click, tap or Escape; counting up the
     dashboard's number tiles as they arrive; and tidying the overlay and the
     entrance classes away once everything has landed, so no animation can
     hold a transform over an element's hover state afterwards. */
  function initLoginWelcome() {
    var overlay = document.querySelector('[data-welcome]');
    if (!overlay) { return; }
    var body = document.body;
    var done = false;

    function finish() {
      if (done) { return; }
      done = true;
      body.classList.remove('ef-entering');
      if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
    }

    function skip() {
      finish();
      if (!reduce) { countUp(0); }
    }

    overlay.addEventListener('click', skip);
    document.addEventListener('keydown', function onKey(e) {
      if (e.key === 'Escape') { document.removeEventListener('keydown', onKey); skip(); }
    });

    var reduce = window.matchMedia &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    /* Entrance finishes at about 3.4s; reduced motion is a short fade, and
       its numbers simply appear rather than count. */
    window.setTimeout(finish, reduce ? 1900 : 3700);
    if (!reduce) { countUp(2500); }
  }

  /* ---- Signing out (24 November 2026) -------------------------------------
     A short "Signing you out..." screen in the same style as the login
     welcome, then the link is followed. auth/logout.php does the real work
     and sends the person to their own sign-in page with a message. Ctrl-,
     Cmd- and middle-clicks are left alone, and with no JavaScript the link
     simply signs out. */
  function initSignOut() {
    $(document).on('click', '[data-signout]', function (e) {
      if (e.button > 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) { return; }
      e.preventDefault();
      var href = this.href;
      if (document.querySelector('.ef-signout')) { return; }

      var team = this.getAttribute('data-signout-team') === '1';
      var overlay = document.createElement('div');
      overlay.className = 'ef-signout';
      overlay.setAttribute('role', 'status');
      overlay.setAttribute('aria-live', 'polite');
      overlay.innerHTML =
        '<div class="ef-signout-card">' +
          '<span class="ef-signout-spinner" aria-hidden="true"></span>' +
          '<h2 class="ef-signout-title">Signing you out...</h2>' +
          '<p class="ef-signout-text">' +
            (team ? 'Closing the help desk session on this browser.'
                  : 'Saving your place. See you next time!') +
          '</p>' +
        '</div>';
      document.body.appendChild(overlay);

      var reduce = window.matchMedia &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      window.setTimeout(function () { window.location.href = href; }, reduce ? 250 : 850);
    });

    /* Back button after signing out: the browser may restore this page from
       its cache with the overlay still on it. Take it away. */
    $(window).on('pageshow', function (e) {
      if (e.originalEvent && e.originalEvent.persisted) {
        $('.ef-signout').remove();
      }
    });

    /* The landing-page toast: leaves by itself after six seconds, sooner if
       dismissed, and waits while the pointer rests on it so it can be read. */
    var toast = document.querySelector('[data-toast]');
    if (toast) {
      var leaveTimer = 0;
      var leave = function () {
        if (toast.classList.contains('is-leaving')) { return; }
        toast.classList.add('is-leaving');
        window.setTimeout(function () { if (toast.parentNode) { toast.parentNode.removeChild(toast); } }, 450);
      };
      var arm = function () { window.clearTimeout(leaveTimer); leaveTimer = window.setTimeout(leave, 6000); };
      toast.addEventListener('mouseenter', function () { window.clearTimeout(leaveTimer); });
      toast.addEventListener('mouseleave', arm);
      var closer = toast.querySelector('[data-toast-close]');
      if (closer) { closer.addEventListener('click', leave); }
      arm();
    }

    /* Show the signed-out message once, then take the flag out of the address
       bar so a refresh or a bookmark does not repeat it. */
    if (document.querySelector('[data-signed-out]') && window.history && history.replaceState) {
      try {
        var url = new URL(window.location.href);
        url.searchParams.delete('signed_out');
        history.replaceState(null, '', url.pathname + url.search + url.hash);
      } catch (err) { /* very old browser: the message simply shows again */ }
    }
  }

  /* Whole-number stat tiles count up from zero. Text such as "2h 15m" is left
     alone, so a value never reads as something it is not. */
  var counters = null, countTimer = 0, countStarted = false;
  function countUp(delay) {
    if (counters === null) {
      counters = [];
      /* Learner stat tiles, and the Admin Dashboard's count tiles. */
      $('.ef-stat-value, .ef-admin-count-n, .ef-admin-headline-n').each(function () {
        var text = $.trim(this.textContent);
        if (!/^[0-9]+$/.test(text) || text === '0') { return; }
        counters.push({ el: this, target: parseInt(text, 10) });
        this.textContent = '0';
      });
    }
    /* A second call (a skip) brings a pending count forward to now. */
    window.clearTimeout(countTimer);
    countTimer = window.setTimeout(runCounters, delay);
  }
  function runCounters() {
    if (countStarted) { return; }
    countStarted = true;
    counters.forEach(function (c) {
      var t0 = null;
      function tick(now) {
        if (t0 === null) { t0 = now; }
        var p = Math.min(1, (now - t0) / 900);
        c.el.textContent = String(Math.round(c.target * (1 - Math.pow(1 - p, 3))));
        if (p < 1) { window.requestAnimationFrame(tick); }
      }
      window.requestAnimationFrame(tick);
    });
  }

  /* Exposed for server-rendered pages that inject rows after load */
  window.EduFlex = {
    masteryBand: masteryBand,
    bandLabel: bandLabel,
    MIN_ITEMS: MIN_ITEMS,
    refresh: function () { renderMasteryRows(); renderBars(); }
  };

})(jQuery);
