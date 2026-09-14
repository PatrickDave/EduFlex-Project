<?php
/**
 * Score the attempt and write the topic's new mastery.
 *
 * This is the endpoint that closes the loop. Everything the dashboard shows
 * changes here, so it does as little as possible itself: the arithmetic lives
 * in includes/attempts.php where it is tested.
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
if ($attemptId <= 0) {
    $reply(['ok' => false, 'error' => 'No attempt specified.'], 400);
}

$user = auth_user();

try {
    $result = attempt_finish($attemptId, (int) $user['user_id']);
} catch (Throwable $e) {
    error_log('EduFlex attempt_finish failed: ' . $e->getMessage());
    $reply(['ok' => false, 'error' => 'The attempt could not be scored.'], 500);
}

// The band label is worked out here rather than in the browser so the screen
// and the database can never disagree about what 84.9 means.
if ($result['ok'] && is_array($result['mastery'] ?? null)) {
    $result['mastery']['band_label'] =
        mastery_band_label((string) $result['mastery']['band']);
}

$reply($result);
