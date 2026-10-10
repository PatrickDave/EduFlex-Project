<?php
/**
 * Manage Profile — Account and Access Management, module 3.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('../../login.php');
$user = auth_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['_csrf'] ?? null)) {
    flash_set('profile_error', 'Your session expired. Please try again.');
    redirect('../settings.php');
}

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$email    = strtolower(trim((string) ($_POST['email'] ?? '')));
$program  = trim((string) ($_POST['program'] ?? ''));
$year     = (int) ($_POST['year_level'] ?? 1);

if ($fullName === '' || mb_strlen($fullName) > 150) {
    flash_set('profile_error', 'Enter your full name.');
    redirect('../settings.php');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash_set('profile_error', 'That does not look like a valid email address.');
    redirect('../settings.php');
}
if ($year < 1 || $year > 5) {
    $year = 1;
}

// The email is unique. Check before updating so the learner gets a clear
// message instead of a constraint violation.
$stmt = db()->prepare('SELECT user_id FROM user WHERE email = ? AND user_id <> ? LIMIT 1');
$stmt->execute([$email, (int) $user['user_id']]);
if ($stmt->fetch()) {
    flash_set('profile_error', 'Another account already uses that email address.');
    redirect('../settings.php');
}

try {
    db()->beginTransaction();
    $emailChanged = $email !== strtolower((string) $user['email']);
    $stmt = db()->prepare(
        'UPDATE user SET full_name = ?, email = ?, program = ?, year_level = ?
          WHERE user_id = ?'
    );
    $stmt->execute([$fullName, $email, mb_substr($program, 0, 100), $year, (int) $user['user_id']]);
    if ($emailChanged && email_verification_enabled()) {
        db()->prepare('UPDATE user SET email_verified_at = NULL WHERE user_id = ?')
            ->execute([(int) $user['user_id']]);
        verification_revoke((int) $user['user_id']);
    }
    db()->commit();

    if ($emailChanged && email_verification_enabled()) {
        auth_logout();
        flash_set('error', 'Your email address changed. Sign in and verify your new email address.');
        redirect('../../login.php');
    }

    // The session carries a copy of the name for greetings; keep it in step.
    $_SESSION['full_name'] = $fullName;
    $_SESSION['email']     = $email;

    flash_set('profile_ok', 'Your profile has been updated.');
} catch (PDOException $e) {
    if (db()->inTransaction()) { db()->rollBack(); }
    error_log('EduFlex profile update failed: ' . $e->getMessage());
    flash_set('profile_error', 'Your profile could not be saved. Try again.');
}

redirect('../settings.php');
