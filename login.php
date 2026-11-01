<?php
/**
 * EduFlex — log in. Handles both the form display (GET) and the
 * submission (POST). Nothing is echoed before this block, so redirects work.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

auth_boot();

// Already signed in? Go straight through, to whichever home is theirs.
if (auth_is_logged_in() && auth_user() !== null) {
    redirect(auth_home_for(auth_user()));
}

$errors = [];
$email  = '';
/* Validated on the way in as well as on the way out, so a crafted link cannot
   put a hostile value into the hidden field in the first place. */
$next   = auth_safe_redirect_target($_GET['next'] ?? null, '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = (string) ($_POST['email'] ?? '');

    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        $result = auth_login($email, (string) ($_POST['password'] ?? ''));
        if (!empty($result['verification_required'])) {
            $_SESSION['_email_pending']['next'] = auth_safe_redirect_target($_POST['next'] ?? null);
            flash_set('verification_error', $result['verification_error'] ?? null);
            redirect('verify_email.php');
        }
        if ($result['ok'] && admin_is_admin(auth_user())) {
            /* A team account came through the learner door. Separate doors was
               Patrick's decision on 1 November 2026, so end the session and
               send them to theirs rather than quietly letting them in here.
               They can still reach the learner screens once signed in, with
               the Learner View link in the help desk sidebar. */
            auth_logout();
            /* A query flag, not a flash. auth_logout() destroys the session, so
               anything flashed after it has nowhere to live and anything
               flashed before it is destroyed too. The same problem was already
               solved this way for account deletion; see $justDeleted below. */
            redirect('admin_login.php?team=1');
        }

        if ($result['ok']) {
            /* Only follow a "next" value that stays inside this application.
               The rule lives in auth_safe_redirect_target(); the check that used
               to be written inline here let `/\evil.example.com` through, which
               was a working open redirect. */
            /* The fallback is role-aware: an admin lands in the support
               queue, a learner on their dashboard. A ?next= value still has to
               survive auth_safe_redirect_target() before it is followed, so
               routing by role does not reopen the redirect hole. */
            redirect(auth_safe_redirect_target(
                $_POST['next'] ?? null, auth_home_for(auth_user())));
        }
        $errors = $result['errors'];
    }
}

$justRegistered = flash_get('registered') !== null;
$loginNotice = flash_get('error');

/* Set by app/actions/delete_account.php. The session is already destroyed by
   the time the learner arrives here, so this cannot be a flash message. It is
   only an acknowledgement, and it names no account. */
$justDeleted = isset($_GET['deleted']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in — EduFlex</title>
<link href="assets/vendor/bootstrap-5.3.3.min.css" rel="stylesheet">
<link href="<?= asset('assets/css/eduflex.css') ?>" rel="stylesheet">
</head>
<body>

<div class="ef-auth-page">

  <nav class="ef-topnav">
    <a href="index.php" class="ef-logo">EduFlex</a>
  </nav>

  <main class="ef-auth-body">
    <?php /* "Back to Home" sits above the card and lines up with its left edge,
             rather than in the nav beside the logo. Two reasons: it reads as a
             way out of this form rather than as navigation for the whole site,
             and in the nav it collided with the logo, because .ef-topnav
             shrink-wraps inside the flex column .ef-auth-page. The collision is
             fixed in the stylesheet either way. */ ?>
    <div class="ef-auth-stack">

      <a href="index.php" class="ef-auth-back">
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true">
          <path d="M9.8 3.4 5.2 8l4.6 4.6" stroke="currentColor"
                stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        Back to Home
      </a>

      <div class="ef-auth-card">

      <?php $current = 'user'; include __DIR__ . '/partials/auth_tabs.php'; ?>

      <h2>Welcome Back</h2>
      <p class="ef-auth-sub">Access your personalized AI learning path</p>

      <?php if ($justRegistered): ?>
        <div class="ef-alert ef-alert-ok">
          Account created. Sign in to continue.
        </div>
      <?php endif; ?>

      <?php if ($loginNotice): ?>
        <div class="ef-alert ef-alert-error" role="alert"><?= e((string) $loginNotice) ?></div>
      <?php endif; ?>

      <?php if ($justDeleted): ?>
        <div class="ef-alert ef-alert-ok">
          Your account and everything stored with it have been deleted.
        </div>
      <?php endif; ?>

      <?php if (!empty($errors['form'])): ?>
        <div class="ef-alert ef-alert-error"><?= e($errors['form']) ?></div>
      <?php endif; ?>

      <form action="login.php" method="post" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= e($next) ?>">

        <div class="ef-field">
          <label class="ef-label" for="email">Email Address</label>
          <input class="ef-input" type="email" id="email" name="email"
                 value="<?= e($email) ?>"
                 placeholder="name@example.com" autocomplete="email" required>
        </div>

        <div class="ef-field">
          <?php
          /* Email verification is available; password reset remains unimplemented.
             Settings supports password changes for learners already signed in. */
          ?>
          <label class="ef-label" for="password">
            Password
            <a href="app/support.php">Cannot sign in?</a>
          </label>
          <div class="ef-password">
            <input class="ef-input" type="password" id="password" name="password"
                   placeholder="••••••••" autocomplete="current-password" required>
            <?= password_toggle_html('password') ?>
          </div>
        </div>

        <?php
        /* Seven-day browser trust is offered after email verification. It skips
           the code, while requiring the password on every new sign-in. */
        ?>

        <button type="submit" class="ef-btn ef-btn-primary ef-btn-block ef-btn-field">
          Log In
          <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <path d="M3 8h9m0 0-3.5-3.5M12 8l-3.5 3.5" stroke="currentColor"
                  stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>
      </form>

      <p class="ef-auth-foot">
        Don't have an account? <a href="register.php">Sign Up</a>
      </p>
      </div><!-- /.ef-auth-card -->

    </div><!-- /.ef-auth-stack -->
  </main>

<?php include __DIR__ . '/partials/public_footer.php'; ?>
</div>

<script src="assets/vendor/jquery-3.7.1.min.js"></script>
<script src="<?= asset('assets/js/eduflex.js') ?>"></script>
</body>
</html>
