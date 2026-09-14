<?php
/**
 * EduFlex — log in. Handles both the form display (GET) and the
 * submission (POST). Nothing is echoed before this block, so redirects work.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

auth_boot();

// Already signed in? Go straight through.
if (auth_is_logged_in() && auth_user() !== null) {
    redirect('app/dashboard.php');
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
        if ($result['ok']) {
            /* Only follow a "next" value that stays inside this application.
               The rule lives in auth_safe_redirect_target(); the check that used
               to be written inline here let `/\evil.example.com` through, which
               was a working open redirect. */
            redirect(auth_safe_redirect_target($_POST['next'] ?? null));
        }
        $errors = $result['errors'];
    }
}

$justRegistered = flash_get('registered') !== null;

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
<link href="assets/css/eduflex.css" rel="stylesheet">
</head>
<body>

<div class="ef-auth-page">

  <nav class="ef-topnav">
    <a href="index.php" class="ef-logo">EduFlex</a>
    <a href="index.php" class="ef-second" style="font-size:13px;font-weight:500;">Back to Home</a>
  </nav>

  <main class="ef-auth-body">
    <div class="ef-auth-card">

      <h2>Welcome Back</h2>
      <p class="ef-auth-sub">Access your personalized AI learning path</p>

      <?php if ($justRegistered): ?>
        <div class="ef-alert ef-alert-ok">
          Account created. Sign in to continue.
        </div>
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
          /* There is no password reset in EduFlex: it needs outbound email,
             which the system deliberately does not have. The link used to be
             href="#", which did nothing at all. It now points at the one thing
             that can actually help, and Settings has Change password for anyone
             who is already signed in. */
          ?>
          <label class="ef-label" for="password">
            Password
            <a href="app/support.php">Cannot sign in?</a>
          </label>
          <input class="ef-input" type="password" id="password" name="password"
                 placeholder="••••••••" autocomplete="current-password" required>
        </div>

        <?php
        /* "Remember me for 30 days" used to sit here as an unchecked box that
           nothing read. The session cookie expires when the browser closes, so
           the label was simply untrue. Removed rather than implemented: a real
           remember-me needs a second long-lived credential table, and that is
           not going in before the feature freeze. Same reasoning as the absent
           notification toggles. */
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
    </div>
  </main>

  <footer class="ef-page-footer">
    <span>&copy; 2026 EduFlex. University of Cebu, College of Computer Studies.</span>
    <span>
      <a href="#">Privacy Policy</a>
      <a href="#">Terms of Service</a>
      <a href="app/support.php">Contact Support</a>
    </span>
  </footer>
</div>

<script src="assets/vendor/jquery-3.7.1.min.js"></script>
<script src="assets/js/eduflex.js"></script>
</body>
</html>
