<?php
/**
 * The project team activates or deactivates a learner's account.
 *
 * admin_require() is here and not only on the page with the button. Without
 * it, any signed-in learner could POST here and lock somebody else out.
 *
 * Team accounts are refused by admin_set_account_status() through
 * admin_user(), so the help desk cannot be locked out from this screen.
 *
 * Returns to the list or to the account, chosen from a fixed pair rather than
 * taken from the POST as given: an unchecked return field is an open redirect.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin.php';

admin_require();

$userId = (int) ($_POST['user_id'] ?? 0);
$back   = (string) ($_POST['from'] ?? '') === 'list'
    ? '../admin_users.php'
    : '../admin_user.php?id=' . $userId;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['_csrf'] ?? null)) {
    flash_set('users_error', 'Your session expired. Please try again.');
    redirect($back);
}

$status = (string) ($_POST['status'] ?? '');
$result = admin_set_account_status($userId, $status);

if (!$result['ok']) {
    flash_set('users_error', (string) $result['error']);
    redirect($back);
}

$target = admin_account($userId);
$email  = (string) ($target['email'] ?? 'The account');

if (!$result['changed']) {
    flash_set('users_ok', sprintf('%s was already %s.', $email,
        strtolower(admin_account_status_label($status))));
} elseif ($status === 'active') {
    flash_set('users_ok', sprintf('%s is active again and can sign in. They have been notified.', $email));
} else {
    flash_set('users_ok', sprintf(
        '%s is deactivated. They cannot sign in, and any open session ends on their next click. '
        . 'Nothing of theirs was deleted.', $email));
}
redirect($back);
