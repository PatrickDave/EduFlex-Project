<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
auth_boot();
header('Cache-Control: no-store');
$loggedIn = auth_is_logged_in();
$pagePending = verification_pending();
if ($loggedIn && ($pagePending['purpose'] ?? '') !== 'profile') { redirect('app/dashboard.php'); }
if (($pagePending['purpose'] ?? '') === 'profile'
    && (!$loggedIn || (int) $pagePending['user_id'] !== (int) $_SESSION['user_id'])) { redirect('login.php'); }
if (!email_verification_enabled() || verification_pending() === null) { redirect('login.php'); }
$error = flash_get('verification_error');
$success = null;
$trust = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $trust = isset($_POST['trust_browser']);
    if (!csrf_check(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (($_POST['action'] ?? '') === 'resend') {
        $result = verification_send();
        $error = $result['error'] ?? null;
        if ($result['ok']) { $success = 'A new code has been sent. Use the most recent email.'; }
    } elseif (($_POST['action'] ?? '') === 'cancel') {
        $returnToSettings = (verification_pending()['purpose'] ?? '') === 'profile';
        unset($_SESSION['_email_pending']);
        redirect($returnToSettings ? 'app/settings.php' : 'login.php');
    } else {
        $code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
        $result = verification_finish($code, $trust);
        $error = $result['error'] ?? null;
        if ($result['ok']) {
            if ($result['purpose'] === 'profile') { flash_set('email_verification_ok', 'Your email address is verified.'); }
            redirect($result['next']);
        }
    }
}
$pending = verification_pending();
if ($pending === null) { flash_set('error', $error); redirect('login.php'); }
$user = verification_user((int) $pending['user_id']);
if (!$user) { unset($_SESSION['_email_pending']); redirect('login.php'); }
$emailParts = explode('@', (string) $user['email'], 2);
$maskedEmail = mb_substr($emailParts[0], 0, 1) . '***@' . ($emailParts[1] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verify your email — EduFlex</title>
<link href="assets/vendor/bootstrap-5.3.3.min.css" rel="stylesheet">
<link href="<?= asset('assets/css/eduflex.css') ?>" rel="stylesheet">
</head>
<body>
<div class="ef-auth-page">
  <nav class="ef-topnav"><a href="index.php" class="ef-logo">EduFlex</a></nav>
  <main class="ef-auth-body">
    <div class="ef-auth-card">
      <h2><?= $pending['purpose'] === 'profile' ? 'Verify your email' : 'Confirm your sign-in' ?></h2>
      <p class="ef-auth-sub">Enter the six-digit code sent to <?= e($maskedEmail) ?>. It expires in 10 minutes. Check your spam folder too.</p>
      <?php if ($error): ?><div class="ef-alert ef-alert-error" role="alert"><?= e((string) $error) ?></div><?php endif; ?>
      <?php if ($success): ?><div class="ef-alert ef-alert-ok" role="status"><?= e($success) ?></div><?php endif; ?>
      <form method="post" action="verify_email.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="verify">
        <div class="ef-field">
          <label class="ef-label" for="code">Verification code</label>
          <input class="ef-input" id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required autofocus aria-describedby="code-help">
          <p id="code-help" class="ef-second">Five incorrect codes temporarily lock verification.</p>
        </div>
        <?php if ($pending['purpose'] === 'login'): ?>
          <div class="ef-field">
            <label><input type="checkbox" name="trust_browser" value="1" <?= $trust ? 'checked' : '' ?>> Trust this browser for 7 days</label>
            <p class="ef-second">Choose this only on your own device. Your password is still required each time you sign in.</p>
          </div>
        <?php endif; ?>
        <button class="ef-btn ef-btn-primary ef-btn-block ef-btn-field" type="submit">Verify code</button>
      </form>
      <form method="post" action="verify_email.php" class="ef-field">
        <?= csrf_field() ?>
        <button class="ef-btn ef-btn-block" type="submit" name="action" value="resend">Send another code</button>
        <p class="ef-second">Wait 60 seconds between requests. Up to five emails per hour.</p>
      </form>
      <form method="post" action="verify_email.php">
        <?= csrf_field() ?>
        <button class="ef-btn ef-btn-block" type="submit" name="action" value="cancel"><?= $pending['purpose'] === 'profile' ? 'Return to settings' : 'Return to sign-in' ?></button>
      </form>
    </div>
  </main>
  <?php include __DIR__ . '/partials/public_footer.php'; ?>
</div>
</body>
</html>
