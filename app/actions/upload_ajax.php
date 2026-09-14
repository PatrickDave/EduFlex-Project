<?php
/**
 * Upload a material from inside the companion chat.
 *
 * Does the upload and the text extraction in one request, then answers JSON so
 * the chat can report what happened without a page reload.
 *
 * Topic detection is deliberately NOT run here. It costs quota, so it stays an
 * explicit choice the learner makes from the message this returns.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/resources.php';

header('Content-Type: application/json; charset=utf-8');

// Extraction of a long PDF is the slow part.
@set_time_limit(180);

$reply = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload);
    exit;
};

if (!auth_is_logged_in() || auth_user() === null) {
    $reply(['ok' => false, 'error' => 'Not signed in.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $reply(['ok' => false, 'error' => 'POST required.'], 405);
}

if (!csrf_check($_POST['_csrf'] ?? null)) {
    $reply(['ok' => false, 'error' => 'Session expired. Reload the page.'], 419);
}

$user = auth_user();
$uid  = (int) $user['user_id'];

try {
    $upload = resource_upload($uid, $_FILES['document'] ?? []);

    if (!$upload['ok']) {
        $reply(['ok' => false, 'error' => $upload['error']], 200);
    }

    $resourceId = (int) $upload['resource_id'];
    $processed  = resource_process($resourceId, $uid);

    // Read the stored row back so the panel and the chat agree on the facts.
    $stmt = db()->prepare(
        'SELECT title, file_type, file_size, char_count, chunk_count,
                processing_status, extract_message
           FROM learning_resource WHERE resource_id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$resourceId, $uid]);
    $row = $stmt->fetch() ?: [];

    $reply([
        'ok'          => $processed['ok'],
        'resourceId'  => $resourceId,
        'title'       => (string) ($row['title'] ?? ''),
        'fileType'    => strtoupper((string) ($row['file_type'] ?? '')),
        'size'        => format_bytes((int) ($row['file_size'] ?? 0)),
        'chunks'      => (int) ($row['chunk_count'] ?? 0),
        'chars'       => (int) ($row['char_count'] ?? 0),
        'status'      => (string) ($row['processing_status'] ?? 'failed'),
        'statusLabel' => resource_status_label((string) ($row['processing_status'] ?? 'failed')),
        'band'        => resource_status_band((string) ($row['processing_status'] ?? 'failed')),
        // A warning means the text was read but may be imperfect; an error
        // means it was not read at all. Both belong in the chat.
        'warning'     => $processed['ok'] ? ($processed['message'] ?? null) : null,
        'error'       => $processed['ok'] ? null : ($processed['message'] ?? 'The file could not be read.'),
    ]);
} catch (Throwable $e) {
    error_log('EduFlex upload_ajax failed: ' . $e->getMessage());
    $reply(['ok' => false, 'error' => 'The upload could not be processed.'], 500);
}
