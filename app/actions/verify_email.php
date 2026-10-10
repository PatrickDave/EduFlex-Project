<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
auth_require_login('../../login.php');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null)) {
    flash_set('email_verification_error', 'Your session expired. Please try again.');
    redirect('../settings.php');
}
if (!email_verification_enabled()) {
    flash_set('email_verification_error', 'Email verification is not configured yet.');
    redirect('../settings.php');
}
$verificationUser = verification_user((int) $_SESSION['user_id']);
if ($verificationUser['email_verified_at'] !== null) {
    flash_set('email_verification_ok', 'Your email address is already verified.');
    redirect('../settings.php');
}
$delivery = verification_begin((int) $_SESSION['user_id'], 'profile');
flash_set('verification_error', $delivery['error'] ?? null);
redirect('../../verify_email.php');
