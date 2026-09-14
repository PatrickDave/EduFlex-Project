<?php
/**
 * Change password — Account and Access Management, module 4.
 *
 * The rule and the verification both live in includes/auth.php. This file only
 * moves POST data into auth_change_password() and turns the result into a flash
 * message. Nothing here logs, echoes or stores a plain password.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('../../login.php');
$user = auth_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['_csrf'] ?? null)) {
    flash_set('password_error', 'Your session expired. Please try again.');
    redirect('../settings.php');
}

$result = auth_change_password(
    (int) $user['user_id'],
    (string) ($_POST['current_password'] ?? ''),
    (string) ($_POST['new_password'] ?? ''),
    (string) ($_POST['confirm_password'] ?? '')
);

if ($result['ok']) {
    flash_set('password_ok', 'Your password has been changed. You are still signed in here.');
} else {
    // One message, the first error. The form has three fields and re-rendering
    // per-field errors would mean passing password input back to the page.
    flash_set('password_error', (string) reset($result['errors']));
}

redirect('../settings.php');
