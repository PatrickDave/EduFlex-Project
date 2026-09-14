<?php
/**
 * Record one answer and report whether it was right.
 *
 * The page never knows the correct answer until the learner has committed to
 * one. That is why this endpoint exists at all: the runner posts a choice, and
 * only the reply carries the truth back.
 *
 * Always answers JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/attempts.php';

header('Content-Type: application/json; charset=utf-8');

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

$attemptId = (int) ($_POST['attempt_id'] ?? 0);
$itemId    = (int) ($_POST['item_id'] ?? 0);
if ($attemptId <= 0 || $itemId <= 0) {
    $reply(['ok' => false, 'error' => 'Incomplete request.'], 400);
}

// An absent answer is a deliberate skip, not an error. It is recorded as an
// incorrect response so it still counts toward the attempt.
$submitted = $_POST['answer'] ?? null;
if (!is_string($submitted) || $submitted === '') {
    $submitted = null;
}

$user = auth_user();

try {
    $result = attempt_answer($attemptId, $itemId, $submitted, (int) $user['user_id']);
} catch (Throwable $e) {
    error_log('EduFlex attempt_answer failed: ' . $e->getMessage());
    $reply(['ok' => false, 'error' => 'That answer could not be recorded.'], 500);
}

$reply($result);
