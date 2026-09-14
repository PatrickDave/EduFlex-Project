<?php
/**
 * EduFlex — sidebar partial.
 *
 * Usage from any page inside /app:
 *
 *     <?php $active = 'dashboard'; include __DIR__ . '/../partials/sidebar.php'; ?>
 *
 * Replaces the hard-coded sidebar that build_pages.py generates into the
 * static HTML. Once every page includes this file, delete build_pages.py.
 */

$active = $active ?? '';

/*
 | Materials is no longer a separate nav item: the library lives inside the AI
 | Learning Companion, so a learner uploads and asks about a document in the
 | same place. materials.php still exists as a full-width management page and
 | is linked from the companion's library panel once a learner has several
 | files. Both read the same functions in includes/resources.php, so there is
 | one source of truth.
 |
 | The seven modules in the Chapter III functional decomposition are unchanged.
 | This merges two screens, not two modules.
 */
$nav = [
    ['key' => 'dashboard', 'label' => 'Dashboard',             'href' => 'dashboard.php'],
    ['key' => 'companion', 'label' => 'AI Learning Companion', 'href' => 'companion.php'],
    ['key' => 'practice',  'label' => 'Practice',              'href' => 'practice.php'],
    ['key' => 'growth',    'label' => 'Growth Insights',       'href' => 'growth.php'],
    ['key' => 'scores',    'label' => 'Monitor Scores',        'href' => 'scores.php'],
    ['key' => 'history',   'label' => 'Review History',        'href' => 'history.php'],
];

$footNav = [
    ['key' => 'support',  'label' => 'Support',  'href' => 'support.php'],
    ['key' => 'settings', 'label' => 'Settings', 'href' => 'settings.php'],
];

/**
 * One outline icon per nav key.
 *
 * Drawn with `stroke="currentColor"` and no fill, so the icon takes the colour
 * of the row it sits in and the active state needs no separate icon rule. Each
 * is a 16 by 16 viewBox to match the rest of the interface, and each is inline
 * rather than a sprite or an icon font, because CLAUDE.md rule 3 forbids
 * fetching anything from outside this server.
 */
function ef_nav_icon(string $key): string
{
    $paths = [
        // Four panels, the usual shorthand for an overview screen.
        'dashboard' => '<rect x="2" y="2" width="5" height="5" rx="1.2"/>'
                     . '<rect x="9" y="2" width="5" height="5" rx="1.2"/>'
                     . '<rect x="2" y="9" width="5" height="5" rx="1.2"/>'
                     . '<rect x="9" y="9" width="5" height="5" rx="1.2"/>',

        // A flask: the companion reads your material and works something out.
        'companion' => '<path d="M6.4 2v4.1L3.2 11.9A1.2 1.2 0 0 0 4.2 13.8h7.6a1.2 1.2 0 0 0 1-1.9L9.6 6.1V2"/>'
                     . '<path d="M5.4 2h5.2"/><path d="M4.7 9.8h6.6"/>',

        // A ticked box: a set of questions, answered.
        'practice'  => '<rect x="2.4" y="2.4" width="11.2" height="11.2" rx="2.4"/>'
                     . '<path d="m5.4 8.1 1.9 1.9 3.3-3.9"/>',

        // Bars rising inside a frame: mastery over time.
        'growth'    => '<rect x="2.4" y="2.4" width="11.2" height="11.2" rx="2.4"/>'
                     . '<path d="M5.4 10.8V8.4M8 10.8V5.6M10.6 10.8V7.2"/>',

        // A trend line under a lens: watching a number move.
        'scores'    => '<path d="M2.3 9.9 5.6 5.9l2.4 2.2 4.6-4.9"/>'
                     . '<circle cx="6.1" cy="11.3" r="2.3"/><path d="m7.8 13 1.6 1.6"/>',

        // A page with lines: what you have already done.
        'history'   => '<path d="M3.2 3.4a1.2 1.2 0 0 1 1.2-1.2h4.2l3.4 3.4v6.9a1.2 1.2 0 0 1-1.2 1.2H4.4a1.2 1.2 0 0 1-1.2-1.2Z"/>'
                     . '<path d="M8.6 2.2v3.4H12"/><path d="M5.8 9h4.4M5.8 11.3h2.9"/>',

        // A question inside a ring.
        'support'   => '<circle cx="8" cy="8" r="5.7"/>'
                     . '<path d="M6.3 6.3a1.8 1.8 0 1 1 2.4 1.7c-.5.2-.7.6-.7 1.1v.3"/>'
                     . '<path d="M8 11.7h.01"/>',

        // A gear, minus the teeth that vanish at this size.
        'settings'  => '<circle cx="8" cy="8" r="2.2"/>'
                     . '<path d="M8 1.7v1.7M8 12.6v1.7M1.7 8h1.7M12.6 8h1.7'
                     . 'M3.5 3.5l1.2 1.2M11.3 11.3l1.2 1.2M12.5 3.5l-1.2 1.2M4.7 11.3l-1.2 1.2"/>',

        // An arrow leaving through a doorway.
        'signout'   => '<path d="M6.3 13.8H3.9a1.2 1.2 0 0 1-1.2-1.2V3.4a1.2 1.2 0 0 1 1.2-1.2h2.4"/>'
                     . '<path d="m10.6 10.8 2.8-2.8-2.8-2.8"/><path d="M13.4 8H6.3"/>',
    ];

    // A nav item with no icon still gets a correctly sized, empty box, so the
    // labels stay aligned rather than jumping left.
    $body = $paths[$key] ?? '';

    return '<span class="ef-nav-ico" aria-hidden="true">'
         . '<svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" '
         . 'stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>'
         . '</span>';
}

function ef_nav_item(array $item, string $active): string
{
    $isActive = $item['key'] === $active;

    return sprintf(
        '<a class="%s" href="%s"%s>%s<span class="ef-nav-label">%s</span></a>',
        $isActive ? 'ef-nav-item is-active' : 'ef-nav-item',
        htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'),
        // Announces the current page to a screen reader, which the colour
        // change alone does not.
        $isActive ? ' aria-current="page"' : '',
        ef_nav_icon($item['key']),
        htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8')
    );
}
?>
<aside class="ef-sidebar">
  <div class="ef-brand">
    <span class="ef-brand-mark">E</span>
    <span>
      <span class="ef-brand-name">EduFlex</span><br>
      <span class="ef-brand-cap">AI Learning Companion</span>
    </span>
  </div>

  <nav class="ef-nav">
    <?php foreach ($nav as $item) { echo ef_nav_item($item, $active) . "\n    "; } ?>
  </nav>

  <div class="ef-sidebar-foot">
    <?php
    /* Straight into questions on whatever EduFlex says is weakest, rather than
       onto a page that asks the learner to choose. The endpoint falls back
       sensibly when there is no recommendation yet, and redirects to Practice
       with a message when there is nothing to practise at all. */
    ?>
    <form method="post" action="actions/practice_next.php" style="margin:0;">
      <?= csrf_field() ?>
      <button class="ef-btn ef-btn-primary ef-btn-block ef-btn-field" type="submit">
        New Practice Session
      </button>
    </form>
    <?php foreach ($footNav as $item) { echo ef_nav_item($item, $active) . "\n    "; } ?>
    <a class="ef-nav-item" href="../auth/logout.php">
      <?= ef_nav_icon('signout') ?><span class="ef-nav-label">Sign out</span>
    </a>
  </div>
</aside>
