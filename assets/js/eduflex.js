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

  $(function () {
    initDrawer();
    renderMasteryRows();
    renderBars();
    initQuizOptions();
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
