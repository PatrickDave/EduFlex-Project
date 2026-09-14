<?php
/**
 * Ask the companion one question.
 *
 * Called over AJAX because a grounded answer takes several seconds and a page
 * reload would lose the conversation on screen.
 *
 * Always answers JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/chat.php';

header('Content-Type: application/json; charset=utf-8');

// One provider call, plus retries on a rate limit.
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

$question = $_POST['question'] ?? '';
if (!is_string($question)) {
    $question = '';
}

// Scoping the question to one document is optional.
$resourceId = isset($_POST['resource_id']) ? (int) $_POST['resource_id'] : 0;
$resourceId = $resourceId > 0 ? $resourceId : null;

$user = auth_user();

try {
    $result = chat_ask((int) $user['user_id'], $question, $resourceId);
} catch (Throwable $e) {
    error_log('EduFlex chat_send failed: ' . $e->getMessage());
    $reply(['ok' => false, 'error' => 'The companion could not answer. '
        . 'Check the PHP error log.'], 500);
}

$reply($result);
