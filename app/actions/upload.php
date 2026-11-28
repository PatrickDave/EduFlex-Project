<?php
/**
 * POST target for the Materials upload form.
 *
 * Stores the file and creates the row, then hands back to materials.php.
 * Extraction does NOT happen here: a large PDF takes long enough to hit
 * Apache's request timeout. The browser triggers process.php afterwards.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/resources.php';

auth_require_login('../../login.php');
$user = auth_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../materials.php');
}

/* BEFORE the CSRF check, because this is the case where the CSRF token never
 * arrived. A POST over post_max_size is discarded whole by PHP, so $_POST is
 * empty and csrf_check() fails: the learner is told their session expired and
 * goes looking at their session. See request_body_discarded(). */
if (request_body_discarded()) {
    flash_set('materials_error', upload_too_large_message());
    redirect('../materials.php');
}

if (!csrf_check($_POST['_csrf'] ?? null)) {
    flash_set('materials_error', 'Your session expired. Please try again.');
    redirect('../materials.php');
}

$result = resource_upload(
    (int) $user['user_id'],
    $_FILES['document'] ?? [],
    (string) ($_POST['title'] ?? '')
);

if (!$result['ok']) {
    flash_set('materials_error', $result['error']);
    redirect('../materials.php');
}

// Tell the page which resource to process, so it can start immediately.
flash_set('materials_process', $result['resource_id']);
flash_set('materials_ok', 'Uploaded. Extracting text now...');
redirect('../materials.php');
