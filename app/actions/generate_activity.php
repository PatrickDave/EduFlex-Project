<?php
/**
 * Generate one practice set for a topic. Called over AJAX because a single
 * generation request takes long enough to time out a page load.
 *
 * Always answers JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/questions.php';

header('Content-Type: application/json; charset=utf-8');

// One model call, plus retries on a rate limit.
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

$topicId = (int) ($_POST['topic_progress_id'] ?? 0);
if ($topicId <= 0) {
    $reply(['ok' => false, 'error' => 'No topic specified.'], 400);
}

// An explicit level is optional; without one the system derives it from the
// learner's last result on this topic.
$bloom = $_POST['bloom_level'] ?? null;
if (!is_string($bloom) || !in_array($bloom, BLOOM_ORDER, true)) {
    $bloom = null;
}

$user = auth_user();

try {
    $result = questions_generate($topicId, (int) $user['user_id'], $bloom);
} catch (Throwable $e) {
    error_log('EduFlex generate_activity failed: ' . $e->getMessage());
    $reply(['ok' => false, 'error' => 'Generation failed. Check the PHP error log.'], 500);
}

$reply([
    'ok'         => $result['ok'],
    'activityId' => $result['activity_id'],
    'items'      => $result['items'],
    'rejected'   => $result['rejected'],
    'bloom'      => $result['bloom'],
    'error'      => $result['error'],
    'topicId'    => $topicId,
]);
