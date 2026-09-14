<?php
/**
 * Delete my account — the other half of the Chapter III ethics commitment.
 *
 * A participant who withdraws must be able to remove their data themselves,
 * without asking the project team to run a query for them.
 *
 * This cannot be undone. auth_delete_account() requires the learner to type
 * their own email address before it will do anything.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('../../login.php');
$user = auth_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['_csrf'] ?? null)) {
    flash_set('delete_error', 'Your session expired. Please try again.');
    redirect('../settings.php');
}

$result = auth_delete_account(
    (int) $user['user_id'],
    (string) ($_POST['confirm_email'] ?? '')
);

if (!$result['ok']) {
    flash_set('delete_error', (string) $result['error']);
    redirect('../settings.php');
}

// The account is gone, so the session points at a user who no longer exists.
// Clear it here rather than letting auth_user() discover that on the next page.
auth_logout();

redirect('../../login.php?deleted=1');
