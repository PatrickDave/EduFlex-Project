<?php
/**
 * EduFlex — top bar partial.
 *
 * Usage:
 *     <?php $title = 'Dashboard'; include __DIR__ . '/../partials/topbar.php'; ?>
 */

require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/avatar.php';

$title = $title ?? 'EduFlex';

/*
 | The bell count. This is a page render, so it only READS; see the rules at
 | the top of includes/notifications.php. It runs on every app page, so it is
 | one COUNT(*) over idx_notification_user_read and nothing more, and
 | notifications_unread_count() returns 0 rather than throwing if the query
 | fails, because a broken bell must not take down every screen.
 */
$unread = function_exists('auth_is_logged_in') && auth_is_logged_in()
    ? notifications_unread_count((int) ($_SESSION['user_id'] ?? 0))
    : 0;
?>
<header class="ef-topbar">
  <div style="display:flex;align-items:center;gap:12px;min-width:0;">
    <button class="ef-burger" type="button" aria-label="Open navigation"><span></span></button>
    <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
  </div>

  <div class="ef-topbar-actions">
    <?php /* Searches the materials library, which is the only thing in EduFlex
             there is enough of to need searching. */ ?>
    <form class="ef-search" action="materials.php" method="get" role="search">
      <svg width="13" height="13" viewBox="0 0 16 16" fill="none" aria-hidden="true">
        <circle cx="7" cy="7" r="5" stroke="currentColor" stroke-width="1.5"/>
        <path d="m11 11 3 3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
      </svg>
      <input type="search" name="q" placeholder="Search materials..."
             value="<?= htmlspecialchars((string) ($_GET['q'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
    </form>

    <a class="ef-icon-btn ef-bell" href="notifications.php"
       aria-label="<?= $unread > 0
            ? htmlspecialchars(notifications_plural($unread, 'unread notification'), ENT_QUOTES, 'UTF-8')
            : 'Notifications' ?>">
      <svg width="17" height="17" viewBox="0 0 16 16" fill="none">
        <path d="M8 2a4 4 0 0 0-4 4v3l-1 2h10l-1-2V6a4 4 0 0 0-4-4Z"
              stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
        <path d="M6.5 13a1.5 1.5 0 0 0 3 0" stroke="currentColor" stroke-width="1.4"/>
      </svg>
      <?php if ($unread > 0): ?>
        <span class="ef-bell-count"><?= $unread > 9 ? '9+' : $unread ?></span>
      <?php endif; ?>
    </a>

    <?php
    /* The learner's picture, or their initials when they have not set one.
       avatar_html() is shared with Settings so the two cannot drift apart. It
       reads the session user rather than taking an id, so there is nothing here
       that could render somebody else's face. */
    ?>
    <a href="settings.php" class="ef-avatar-link" aria-label="Profile settings">
      <?php if (function_exists('auth_is_logged_in') && auth_is_logged_in()): ?>
        <?= avatar_html(
              (int) ($_SESSION['user_id'] ?? 0),
              (string) ($_SESSION['full_name'] ?? '')
            ) ?>
      <?php else: ?>
        <span class="ef-avatar"></span>
      <?php endif; ?>
    </a>
  </div>
</header>
