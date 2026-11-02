<?php
/**
 * Export one learner's data, on their behalf.
 *
 * The same JSON a learner can download for themselves from Settings, produced
 * by the same auth_export_data(). It is here because a participant who asks the
 * team for their data by email should not have to be walked through finding the
 * button, and because Chapter III promises the right of access, not the right
 * of access provided you can navigate to it.
 *
 * admin_user() returns null for a team account as well as a missing one, so a
 * crafted id cannot pull another admin's record through a screen meant for
 * participants.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin.php';

admin_require();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['_csrf'] ?? null)) {
    flash_set('users_error', 'Your session expired. Please try again.');
    redirect('../admin_settings.php');
}

$target = admin_user((int) ($_POST['user_id'] ?? 0));

if ($target === null) {
    flash_set('users_error', 'That learner no longer exists.');
    redirect('../admin_settings.php');
}

try {
    $data = auth_export_data((int) $target['user_id']);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('EduFlex admin export failed: ' . $e->getMessage());
    flash_set('users_error', 'That export could not be produced.');
    redirect('../admin_settings.php');
}

if ($json === false) {
    error_log('EduFlex admin export could not be encoded: ' . json_last_error_msg());
    flash_set('users_error', 'That export could not be produced.');
    redirect('../admin_settings.php');
}

/* The learner's id rather than their name in the filename: a name goes through
   a file system, a mail client and whoever is looking over your shoulder, and
   the id is enough to tell two exports apart. */
$filename = sprintf('eduflex-export-user-%d-%s.json',
    (int) $target['user_id'], date('Y-m-d'));

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($json));
header('X-Content-Type-Options: nosniff');

echo $json;
