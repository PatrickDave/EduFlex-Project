<?php
/**
 * A learner answers the project team on one of their own requests.
 *
 * Ownership is checked inside support_learner_reply(), in SQL, by user_id. The
 * request id in the POST body is never trusted on its own: a learner who edits
 * it to somebody else's request gets "could not be found", the same answer as
 * for an id that does not exist.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/support.php';

auth_require_login('../../login.php');
$user = auth_user();

$requestId = (int) ($_POST['request_id'] ?? 0);
$back      = '../support_thread.php?id=' . $requestId;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['_csrf'] ?? null)) {
    flash_set('support_error', 'Your session expired. Please try again.');
    redirect($requestId > 0 ? $back : '../support.php');
}

$message = (string) ($_POST['message'] ?? '');
$result  = support_learner_reply((int) $user['user_id'], $requestId, $message);

if (!$result['ok']) {
    flash_set('support_error', (string) $result['error']);
    flash_set('support_reply_draft', $message);
    redirect($back);
}

flash_set(
    'support_ok',
    $result['reopened']
        ? 'Reply sent. The request was marked Resolved, so it has been reopened for the team.'
        : 'Reply sent. The project team will see it the next time they review requests.'
);
redirect($back);
