<?php
/**
 * The project team replies to one support request.
 *
 * admin_require() is called here and not only on the page that shows the form.
 * A gate on the screen is not a gate on the system: without this, anyone
 * signed in could POST here and write a "team" reply into another learner's
 * conversation.
 *
 * Always returns to the conversation it came from. The only input that shapes
 * the redirect is the request id, cast to an integer, so there is no "back"
 * field to turn into an open redirect.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin.php';

admin_require();

$requestId = (int) ($_POST['request_id'] ?? 0);
$back      = '../admin_thread.php?id=' . $requestId;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['_csrf'] ?? null)) {
    flash_set('admin_error', 'Your session expired. Please try again.');
    redirect($requestId > 0 ? $back : '../admin_requests.php');
}

$message = (string) ($_POST['message'] ?? '');
$resolve = (string) ($_POST['resolve'] ?? '') === '1';

$result = admin_reply((int) auth_user()['user_id'], $requestId, $message, $resolve);

if (!$result['ok']) {
    if ($result['status'] === '') {
        // The request itself is gone; there is no conversation to go back to.
        flash_set('admin_error', (string) $result['error']);
        redirect('../admin_requests.php');
    }
    flash_set('admin_error', (string) $result['error']);
    flash_set('admin_reply_draft', $message);
    redirect($back);
}

flash_set(
    'admin_ok',
    'Reply sent. The learner has been notified'
    . ($result['status'] === 'resolved' && $resolve ? ' and the request is marked Resolved.' : '.')
);
redirect($back);
