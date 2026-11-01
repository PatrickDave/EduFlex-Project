<?php
/**
 * EduFlex — the project team's sign-in.
 *
 * A door of its own, as Patrick asked for on 1 November 2026. It authenticates
 * through auth_login() like the learner form does, so there is still ONE
 * password rule, one login throttle and one email-verification path: a second
 * copy of any of those is a second thing to keep correct, and the hardened one
 * in includes/auth.php took real work to get right.
 *
 * What is separate is who may come through. This page admits only accounts with
 * is_admin set, and login.php now refuses them and points here. A team member
 * who wants to see what a participant sees crosses over with the Learner View
 * link inside the application rather than by signing in twice.
 *
 * It is deliberately quiet about why a sign-in failed. "Those details do not
 * match a team account" is returned both for a wrong password and for a correct
 * password on a learner account, so this page cannot be used to discover which
 * addresses are admins.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

auth_boot();

// Already signed in? Send them wherever they belong, admin or not.
if (auth_is_logged_in() && auth_user() !== null) {
    redirect(auth_home_for(auth_user()));
}

$errors = [];
$email  = '';

/* Set by login.php when a team account tries the learner door. A query flag
   rather than a flash, because auth_logout() destroys the session on the way
   here: a flash set before it is destroyed with the session, and one set after
   it has no session to live in. login.php explains the same thing. */
$fromLearnerDoor = isset($_GET['team']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = (string) ($_POST['email'] ?? '');

    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $errors['form'] = 'Your session expired. Please try again.';
    } else {
        $result = auth_login($email, (string) ($_POST['password'] ?? ''));

        if (!empty($result['verification_required'])) {
            $_SESSION['_email_pending']['next'] = 'app/admin.php';
            flash_set('verification_error', $result['verification_error'] ?? null);
            redirect('verify_email.php');
        }

        if ($result['ok']) {
            if (admin_is_admin(auth_user())) {
                redirect('app/admin.php');
            }

            /* Correct credentials, but not a team account. End the session
               rather than leaving them signed in as a learner on a page that
               told them they were signing in as staff. */
            auth_logout();
            $errors['form'] = 'Those details do not match a team account.';
        } else {
            // Same wording whatever went wrong, so this page cannot be used to
            // work out which addresses belong to the team.
            $errors['form'] = 'Those details do not match a team account.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Team sign-in — EduFlex</title>
<link href="assets/vendor/bootstrap-5.3.3.min.css" rel="stylesheet">
<link href="<?= asset('assets/css/eduflex.css') ?>" rel="stylesheet">
</head>
<body>

<div class="ef-auth-page">

  <nav class="ef-topnav">
    <a href="index.php" class="ef-logo">EduFlex</a>
  </nav>

  <main class="ef-auth-body">
    <div class="ef-auth-stack">

      <a href="login.php" class="ef-auth-back">
        <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true">
          <path d="M9.8 3.4 5.2 8l4.6 4.6" stroke="currentColor"
                stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
        Learner sign-in
      </a>

      <div class="ef-auth-card ef-auth-card-admin">

        <?php $current = 'admin'; include __DIR__ . '/partials/auth_tabs.php'; ?>

        <h2>Help Desk</h2>
        <p class="ef-auth-sub">Sign in to read and answer learner requests</p>

        <?php if ($fromLearnerDoor): ?>
          <div class="ef-alert ef-alert-info">
            That is a project team account. Please use the team sign-in below.
          </div>
        <?php endif; ?>
        <?php if (isset($errors['form'])): ?>
          <div class="ef-alert ef-alert-error"><?= e($errors['form']) ?></div>
        <?php endif; ?>

        <form method="post" action="admin_login.php" novalidate>
          <?= csrf_field() ?>

          <div class="ef-field">
            <label class="ef-label" for="email">Team email address</label>
            <input class="ef-input" type="email" id="email" name="email"
                   value="<?= e($email) ?>" placeholder="name@example.com"
                   autocomplete="username" required>
          </div>

          <div class="ef-field">
            <label class="ef-label" for="password">Password</label>
            <div class="ef-password">
              <input class="ef-input" type="password" id="password" name="password"
                     placeholder="••••••••" autocomplete="current-password" required>
              <?= password_toggle_html('password') ?>
            </div>
          </div>

          <button type="submit" class="ef-btn ef-btn-primary ef-btn-block ef-btn-field">
            Sign in
            <svg width="15" height="15" viewBox="0 0 16 16" fill="none" aria-hidden="true">
              <path d="M3 8h9m0 0-3.5-3.5M12 8l-3.5 3.5" stroke="currentColor"
                    stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>
        </form>

        <p class="ef-auth-foot">
          Not a team member? <a href="login.php">Learner sign-in</a>
        </p>
      </div><!-- /.ef-auth-card -->

    </div><!-- /.ef-auth-stack -->
  </main>

<?php include __DIR__ . '/partials/public_footer.php'; ?>

<script src="assets/vendor/jquery-3.7.1.min.js"></script>
<script src="<?= asset('assets/js/eduflex.js') ?>"></script>
</body>
</html>
