<?php
/**
 * Run topic detection for one resource. Called over AJAX, because a document
 * with several chunks makes several model calls and can take a minute.
 *
 * Always answers JSON, never an HTML error page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/topics.php';

header('Content-Type: application/json; charset=utf-8');

// Several sequential model calls, each with retries. Give it room.
@set_time_limit(300);

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
    $result = topics_detect($resourceId, (int) $user['user_id']);
} catch (Throwable $e) {
    error_log('EduFlex detect_topics failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Topic detection failed. Check the PHP error log.',
    ]);
    exit;
}

echo json_encode([
    'ok'         => $result['ok'],
    'topics'     => $result['topics'],
    'new'        => $result['new'],
    'calls'      => $result['calls'],
    'failed'     => $result['failed'],
    'chunks'     => $result['chunks'],
    'names'      => array_slice($result['names'], 0, 8),
    'error'      => $result['error'],
    'resourceId' => $resourceId,
]);
