<?php
/**
 * The project team edits a learner's account details.
 *
 * admin_require() is here and not only on the page with the form. A gate on
 * the screen is not a gate on the system: without this, any signed-in learner
 * could POST here and rename somebody else.
 *
 * admin_update_account() refuses team accounts through admin_user(), so a
 * crafted id cannot rewrite another admin's address. The redirect is built
 * from the cast integer id only.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin.php';

admin_require();

$userId = (int) ($_POST['user_id'] ?? 0);
$back   = '../admin_user.php?id=' . $userId;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['_csrf'] ?? null)) {
    flash_set('users_error', 'Your session expired. Please try again.');
    redirect($userId > 0 ? $back : '../admin_users.php');
}

$fields = [
    'full_name'  => (string) ($_POST['full_name'] ?? ''),
    'email'      => (string) ($_POST['email'] ?? ''),
    'program'    => (string) ($_POST['program'] ?? ''),
    'year_level' => (string) ($_POST['year_level'] ?? ''),
];

$result = admin_update_account(
    $userId,
    $fields['full_name'],
    $fields['email'],
    $fields['program'],
    (int) $fields['year_level']
);

if (!$result['ok']) {
    flash_set('users_error', (string) ($result['errors']['form'] ?? 'Check the highlighted fields.'));
    flash_set('users_field_errors', $result['errors']);
    flash_set('users_draft', $fields);
    redirect($back);
}

flash_set('users_ok', !$result['changed']
    ? 'Nothing changed, so nothing was saved.'
    : ($result['email_changed']
        ? 'Saved. The email changed, so the learner must verify the new address at their next sign-in.'
        : 'Saved. The learner has been notified.'));
redirect($back);
