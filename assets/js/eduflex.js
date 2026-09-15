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

  $(function () {
    initDrawer();
    renderMasteryRows();
    renderBars();
    initQuizOptions();
    initLanding();
    $(window).on('resize', renderBars);
  });

  /* Exposed for server-rendered pages that inject rows after load */
  window.EduFlex = {
    masteryBand: masteryBand,
    bandLabel: bandLabel,
    MIN_ITEMS: MIN_ITEMS,
    refresh: function () { renderMasteryRows(); renderBars(); }
  };

})(jQuery);
