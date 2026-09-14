<?php
/**
 * Extract and chunk one resource. Called by the browser over AJAX so the
 * upload request itself stays fast and never hits a request timeout.
 *
 * Returns JSON. Never returns HTML, even on error, because the caller parses
 * the response as JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/resources.php';

header('Content-Type: application/json; charset=utf-8');

// Extraction of a long PDF can take a while. Raise the limit for this script
// only; it does not affect the rest of the application.
@set_time_limit(180);

if (!auth_is_logged_in() || auth_user() === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not signed in.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}

if (!csrf_check($_POST['_csrf'] ?? null)) {
    http_response_code(419);
    echo json_encode(['ok' => false, 'error' => 'Session expired. Reload the page.']);
    exit;
}

$resourceId = (int) ($_POST['resource_id'] ?? 0);
if ($resourceId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No resource specified.']);
    exit;
}

$user = auth_user();

try {
    $result = resource_process($resourceId, (int) $user['user_id']);
} catch (Throwable $e) {
    // The caller parses this response as JSON, so an HTML fatal-error page
    // would leave the interface stuck. Always answer in the agreed format.
    error_log('EduFlex process endpoint failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok'          => false,
        'status'      => 'failed',
        'statusLabel' => 'Failed',
        'band'        => 'weak',
        'message'     => 'The document could not be processed.',
        'chunks'      => 0,
        'chars'       => 0,
        'resourceId'  => $resourceId,
    ]);
    exit;
}

echo json_encode([
    'ok'          => $result['ok'],
    'status'      => $result['status'],
    'statusLabel' => resource_status_label($result['status']),
    'band'        => resource_status_band($result['status']),
    'message'     => $result['message'],
    'chunks'      => $result['chunks'],
    'chars'       => $result['chars'],
    'resourceId'  => $resourceId,
]);
