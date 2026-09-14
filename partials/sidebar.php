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

function ef_nav_item(array $item, string $active): string
{
    $cls = $item['key'] === $active ? 'ef-nav-item is-active' : 'ef-nav-item';
    return sprintf(
        '<a class="%s" href="%s"><span class="ef-nav-ico"></span>%s</a>',
        $cls,
        htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'),
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
      <span class="ef-nav-ico"></span>Sign out
    </a>
  </div>
</aside>
