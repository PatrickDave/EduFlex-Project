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
$next   = $_GET['next'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = (string) ($_POST['email'] ?? '');

    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        $result = auth_login($email, (string) ($_POST['password'] ?? ''));
        if ($result['ok']) {
            $target = 'app/dashboard.php';
            // Only follow a "next" value that stays inside this application.
            $candidate = (string) ($_POST['next'] ?? '');
            if ($candidate !== '' && !preg_match('#^(https?:)?//#', $candidate)) {
                $target = $candidate;
            }
            redirect($target);
        }
        $errors = $result['errors'];
    }
}

$justRegistered = flash_get('registered') !== null;
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
          <label class="ef-label" for="password">
            Password
            <a href="#">Forgot Password?</a>
          </label>
          <input class="ef-input" type="password" id="password" name="password"
                 placeholder="••••••••" autocomplete="current-password" required>
        </div>

        <label class="ef-check" style="margin-bottom:20px;">
          <input type="checkbox" name="remember" value="1">
          Remember me for 30 days
        </label>

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
      <a href="#">Contact Support</a>
    </span>
  </footer>
</div>

<script src="assets/vendor/jquery-3.7.1.min.js"></script>
<script src="assets/js/eduflex.js"></script>
</body>
</html>
