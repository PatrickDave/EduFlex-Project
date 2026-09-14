<?php
/**
 * Export my data — the withdrawal half of the Chapter III ethics commitment.
 *
 * Sends one JSON file containing everything EduFlex holds about the signed-in
 * learner. No language model, no third party, nothing leaves this server except
 * to the browser that asked.
 *
 * A POST rather than a GET, so another site cannot pull a learner's whole
 * record with a single <img> tag pointed at this URL.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

auth_require_login('../../login.php');
$user = auth_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['_csrf'] ?? null)) {
    flash_set('export_error', 'Your session expired. Please try again.');
    redirect('../settings.php');
}

try {
    $data = auth_export_data((int) $user['user_id']);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('EduFlex data export failed: ' . $e->getMessage());
    flash_set('export_error', 'Your data could not be exported. Tell the project team.');
    redirect('../settings.php');
}

if ($json === false) {
    error_log('EduFlex data export could not be encoded: ' . json_last_error_msg());
    flash_set('export_error', 'Your data could not be exported. Tell the project team.');
    redirect('../settings.php');
}

$filename = 'eduflex-export-' . date('Y-m-d') . '.json';

// No output has been sent yet, so these headers are safe. Content-Length lets
// the browser show real download progress.
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($json));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

echo $json;
exit;
