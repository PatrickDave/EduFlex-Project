<?php
/**
 * EduFlex — top bar partial.
 *
 * Usage:
 *     <?php $title = 'Dashboard'; include __DIR__ . '/../partials/topbar.php'; ?>
 */

$title = $title ?? 'EduFlex';
?>
<header class="ef-topbar">
  <div style="display:flex;align-items:center;gap:12px;min-width:0;">
    <button class="ef-burger" type="button" aria-label="Open navigation"><span></span></button>
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
  </div>

  <div class="ef-topbar-actions">
    <form class="ef-search" action="search.php" method="get" role="search">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="none" aria-hidden="true">
        <circle cx="7" cy="7" r="5" stroke="currentColor" stroke-width="1.5"/>
        <path d="m11 11 3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
      </svg>
      <input type="search" name="q" placeholder="Search materials...">
    </form>

    <a class="ef-icon-btn" href="notifications.php" aria-label="Notifications">
      <svg width="17" height="17" viewBox="0 0 16 16" fill="none">
        <path d="M8 2a4 4 0 0 0-4 4v3l-1 2h10l-1-2V6a4 4 0 0 0-4-4Z"
              stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
        <path d="M6.5 13a1.5 1.5 0 0 0 3 0" stroke="currentColor" stroke-width="1.4"/>
      </svg>
    </a>

    <a href="settings.php" class="ef-avatar" aria-label="Profile"></a>
  </div>
</header>
