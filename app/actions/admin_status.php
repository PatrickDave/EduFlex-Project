<?php
/**
 * Move one support request along.
 *
 * Support and Feedback, the project team's side. The first thing in EduFlex
 * that writes to support_request after creation, and therefore the first thing
 * that makes "View Request Status" mean anything to the learner who sent it.
 *
 * admin_require() is called here and not only on the page that links to it. A
 * gate on the screen is not a gate on the system: without this, anyone signed
 * in could POST to this endpoint and change another learner's request.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin.php';

admin_require();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['_csrf'] ?? null)) {
    flash_set('admin_error', 'Your session expired. Please try again.');
    redirect('../admin.php');
}

$requestId = (int) ($_POST['request_id'] ?? 0);
$status    = (string) ($_POST['status'] ?? '');

/* Which admin screen to return to. Chosen from a fixed list rather than taken
   from the POST as given. An unchecked "back" field is an open redirect wearing
   a different hat, and that exact mistake has already been made once in this
   codebase: see auth_safe_redirect_target() and README 1c. */
$pages = ['admin.php', 'admin_requests.php', 'admin_feedback.php'];
$page  = (string) ($_POST['back'] ?? 'admin_requests.php');
if (!in_array($page, $pages, true)) {
    $page = 'admin_requests.php';
}

// Keep the filters the admin was looking at, so the list does not jump back to
// "all" on every change.
$query = [];
foreach (['status_filter' => 'status', 'type_filter' => 'type'] as $field => $key) {
    $value = trim((string) ($_POST[$field] ?? ''));
    if ($value !== '') {
        $query[$key] = $value;
    }
}
$back = '../' . $page . ($query === [] ? '' : '?' . http_build_query($query));

// admin_set_status() checks $status against SUPPORT_STATUSES, so the POST body
// cannot put an arbitrary string into the status column.
$result = admin_set_status($requestId, $status);

if (!$result['ok']) {
    flash_set('admin_error', (string) $result['error']);
    redirect($back);
}

flash_set(
    'admin_ok',
    $result['changed']
        ? 'Request #' . $requestId . ' is now ' . support_status_label($status) . '.'
        : 'Request #' . $requestId . ' was already ' . support_status_label($status) . '.'
);
redirect($back);
