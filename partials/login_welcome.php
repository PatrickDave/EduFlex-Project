<?php
/**
 * The "Login Successful!" screen, played once over the first page a learner
 * lands on after signing in, then swept away to reveal that page.
 *
 * Added 20 November 2026. Included by SHELL in build_pages.py only when
 * $_SESSION['_login_welcome'] was set, and that is set only by
 * auth_complete_login() in includes/auth.php: after the password, after any
 * emailed code, after the account is confirmed active. So this file can never
 * be shown for a failed sign-in. The server decides; the animation follows.
 *
 * Timing lives in section 19 of eduflex.css, so the whole sequence runs with no
 * JavaScript at all. eduflex.js only adds three courtesies: click, tap or
 * Escape skips it; the stat counters count up; and the overlay is removed from
 * the page once it has finished, as a backstop if an animation never ends.
 *
 * The page underneath is fully rendered from the start, so nothing waits on
 * this: it is a 2.3 second curtain, not a loading screen.
 */

declare(strict_types=1);

/* The team gets its own wording (added 24 November 2026): a shared help desk
   account is not starting a "personalized learning experience". SHELL sets
   $loginWelcomeTeam from admin_is_admin(), never from anything the browser
   sends. */
$welcomeTeam = !empty($loginWelcomeTeam);
$welcomeText = $welcomeTeam
    ? 'Welcome back, EduFlex Team! Opening the Admin Dashboard...'
    : 'Welcome back to EduFlex! Preparing your personalized learning experience...';
?>
<div class="ef-welcome" data-welcome role="status" aria-live="polite">

  <div class="ef-welcome-particles" aria-hidden="true">
    <span style="--x: 12%; --y: 22%; --s: 6px;  --d: 0s;"></span>
    <span style="--x: 22%; --y: 70%; --s: 4px;  --d: .3s;"></span>
    <span style="--x: 31%; --y: 38%; --s: 3px;  --d: .7s;"></span>
    <span style="--x: 40%; --y: 82%; --s: 5px;  --d: .15s;"></span>
    <span style="--x: 47%; --y: 16%; --s: 4px;  --d: .5s;"></span>
    <span style="--x: 56%; --y: 76%; --s: 6px;  --d: .9s;"></span>
    <span style="--x: 63%; --y: 28%; --s: 3px;  --d: .2s;"></span>
    <span style="--x: 70%; --y: 62%; --s: 5px;  --d: .6s;"></span>
    <span style="--x: 78%; --y: 20%; --s: 4px;  --d: .35s;"></span>
    <span style="--x: 86%; --y: 74%; --s: 6px;  --d: .8s;"></span>
    <span style="--x: 92%; --y: 40%; --s: 3px;  --d: .45s;"></span>
    <span style="--x: 7%;  --y: 56%; --s: 5px;  --d: .65s;"></span>
  </div>

  <div class="ef-welcome-card">
    <div class="ef-welcome-badge" aria-hidden="true">
      <span class="ef-welcome-ring"></span>
      <span class="ef-welcome-ring ef-welcome-ring-2"></span>
      <svg class="ef-welcome-check" viewBox="0 0 52 52">
        <defs>
          <linearGradient id="efWelcomeGrad" x1="0" x2="1" y1="0" y2="1">
            <stop offset="0" stop-color="#38BDF8"/>
            <stop offset=".55" stop-color="#22D3EE"/>
            <stop offset="1" stop-color="#2563EB"/>
          </linearGradient>
        </defs>
        <circle class="ef-welcome-disc" cx="26" cy="26" r="24"/>
        <circle class="ef-welcome-circle" cx="26" cy="26" r="24"/>
        <path class="ef-welcome-tick" d="M15.5 27.2 L22.6 34.2 L37 19.2"/>
      </svg>
    </div>

    <h2 class="ef-welcome-title">Login Successful!</h2>
    <p class="ef-welcome-text"><?= e($welcomeText) ?></p>
    <div class="ef-welcome-progress" aria-hidden="true"><span></span></div>
  </div>

  <div class="ef-welcome-sweep" aria-hidden="true"></div>

  <button type="button" class="ef-welcome-skip" data-welcome-skip>Skip</button>
</div>
