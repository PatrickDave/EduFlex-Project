<?php
/**
 * Record one support request.
 *
 * Account and Access Management, Get Support. Nothing is emailed and no ticket
 * is opened anywhere: the row is stored for the project team to read. See the
 * note at the top of includes/support.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/support.php';

auth_require_login('../../login.php');
$user = auth_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['_csrf'] ?? null)) {
    flash_set('support_error', 'Your session expired. Please try again.');
    redirect('../support.php');
}

$type    = (string) ($_POST['request_type'] ?? '');
$subject = (string) ($_POST['subject'] ?? '');
$message = (string) ($_POST['message'] ?? '');

// support_create() checks $type against SUPPORT_TYPES, so the POST body cannot
// put an arbitrary string into the request_type column.
$result = support_create((int) $user['user_id'], $type, $subject, $message);

if ($result['ok']) {
    flash_set(
        'support_ok',
        'Your request has been recorded. It is visible to the project team, and to you '
        . 'in the list below. Nobody is on duty to reply, so expect an answer only when '
        . 'the team next reviews requests.'
    );
    redirect('../support.php');
}

// Hand the typed text back so the learner does not have to write it again.
flash_set('support_errors', $result['errors']);
flash_set('support_draft', [
    'request_type' => $type,
    'subject'      => $subject,
    'message'      => $message,
]);
redirect('../support.php');
