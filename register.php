<?php
/**
 * EduFlex — create account. Handles display (GET) and submission (POST).
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

auth_boot();

if (auth_is_logged_in() && auth_user() !== null) {
    redirect('app/dashboard.php');
}

$errors   = [];
$fullName = '';
$email    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = (string) ($_POST['full_name'] ?? '');
    $email    = (string) ($_POST['email'] ?? '');

    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        $result = auth_register(
            $fullName,
            $email,
            (string) ($_POST['password'] ?? ''),
            isset($_POST['agree'])
        );

        if ($result['ok']) {
            flash_set('registered', true);
            redirect('login.php');
        }
        $errors = $result['errors'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create your account — EduFlex</title>
<link href="assets/vendor/bootstrap-5.3.3.min.css" rel="stylesheet">
<link href="assets/css/eduflex.css" rel="stylesheet">
</head>
<body>

<div class="ef-auth-page">

  <nav class="ef-topnav">
    <a href="index.php" class="ef-logo">EduFlex</a>
    <span class="ef-second" style="font-size:12px;font-weight:500;">Step into the future of learning</span>
  </nav>

  <main class="ef-auth-body">
    <div class="ef-auth-card">

      <h2>Create Your Account</h2>
      <p class="ef-auth-sub">Start your personalized learning journey with EduFlex.</p>

      <?php if (!empty($errors['form'])): ?>
        <div class="ef-alert ef-alert-error"><?= e($errors['form']) ?></div>
      <?php endif; ?>

      <form action="register.php" method="post" novalidate>
        <?= csrf_field() ?>

        <div class="ef-field">
          <label class="ef-label" for="name">Full Name</label>
          <input class="ef-input<?= isset($errors['full_name']) ? ' is-invalid' : '' ?>"
                 type="text" id="name" name="full_name" value="<?= e($fullName) ?>"
                 placeholder="John Doe" autocomplete="name" required>
          <?php if (isset($errors['full_name'])): ?>
            <p class="ef-error"><?= e($errors['full_name']) ?></p>
          <?php endif; ?>
        </div>

        <div class="ef-field">
          <label class="ef-label" for="email">Email</label>
          <input class="ef-input<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
                 type="email" id="email" name="email" value="<?= e($email) ?>"
                 placeholder="john@example.com" autocomplete="email" required>
          <?php if (isset($errors['email'])): ?>
            <p class="ef-error"><?= e($errors['email']) ?></p>
          <?php endif; ?>
        </div>

        <div class="ef-field">
          <label class="ef-label" for="password">Password</label>
          <input class="ef-input<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                 type="password" id="password" name="password"
                 placeholder="••••••••" autocomplete="new-password" minlength="8" required>
          <?php if (isset($errors['password'])): ?>
            <p class="ef-error"><?= e($errors['password']) ?></p>
          <?php else: ?>
            <p class="ef-note">At least 8 characters.</p>
          <?php endif; ?>
        </div>

        <label class="ef-check" style="margin-bottom:8px;">
          <input type="checkbox" name="agree" value="1" required>
          <span>I agree to the <a href="#" class="ef-primary" style="font-weight:600;">Terms</a>
          and <a href="#" class="ef-primary" style="font-weight:600;">Privacy Policy</a></span>
        </label>
        <?php if (isset($errors['agree'])): ?>
          <p class="ef-error" style="margin-bottom:12px;"><?= e($errors['agree']) ?></p>
        <?php else: ?>
          <div style="height:12px;"></div>
        <?php endif; ?>

        <button type="submit" class="ef-btn ef-btn-primary ef-btn-block ef-btn-field">
          Sign Up
          <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <path d="M3 8h9m0 0-3.5-3.5M12 8l-3.5 3.5" stroke="currentColor"
                  stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>
      </form>

      <p class="ef-auth-foot">
        Already have an account? <a href="login.php">Log In</a>
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
