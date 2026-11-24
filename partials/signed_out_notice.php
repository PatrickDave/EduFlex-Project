<?php
/**
 * "You have signed out" — shown at the top of the sign-in card after
 * auth/logout.php sends someone back to their door. Added 24 November 2026.
 *
 * Expects $signedOutTeam (bool): true on admin_login.php, false on login.php.
 * The wording is fixed here; nothing from the query string is printed, so the
 * flag cannot be used to put text on the page.
 *
 * Styles: section 21 of eduflex.css. eduflex.js removes ?signed_out=1 from the
 * address bar after showing this, so a refresh does not repeat it.
 */

declare(strict_types=1);

$signedOutTeam = !empty($signedOutTeam);
?>
<div class="ef-signout-note" role="status" data-signed-out>
  <span class="ef-signout-note-icon" aria-hidden="true">
    <svg viewBox="0 0 24 24" fill="none">
      <circle cx="12" cy="12" r="10.5" class="ef-signout-note-ring"/>
      <path d="M7.4 12.4 10.5 15.4 16.6 9" class="ef-signout-note-tick"/>
    </svg>
  </span>
  <span class="ef-signout-note-text">
    <?php if ($signedOutTeam): ?>
      <strong>You have signed out of the Admin Dashboard.</strong>
      <span>The help desk session on this browser is closed. Sign in again whenever you need it.</span>
    <?php else: ?>
      <strong>You have signed out successfully.</strong>
      <span>See you again soon! Sign in whenever you are ready to keep learning.</span>
    <?php endif; ?>
  </span>
</div>
