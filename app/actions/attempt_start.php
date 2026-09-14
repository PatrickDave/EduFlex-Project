<?php
/**
 * Start (or resume) an attempt at a practice set, then hand off to the runner.
 *
 * This one is a plain form post rather than AJAX. Starting a set is a
 * navigation: the learner expects to land on the questions, and a redirect is
 * the honest way to do that. It also means the Start button still works if
 * JavaScript fails.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/attempts.php';

// One directory deeper than the app pages, so the login path needs a level.
auth_require_login('../../login.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../practice.php');
}
if (!csrf_check($_POST['_csrf'] ?? null)) {
    flash_set('error', 'Your session expired. Please try again.');
    redirect('../practice.php');
}

$activityId = (int) ($_POST['activity_id'] ?? 0);
if ($activityId <= 0) {
    flash_set('error', 'No practice set was chosen.');
    redirect('../practice.php');
}

$user   = auth_user();
$result = attempt_start($activityId, (int) $user['user_id']);

if (!$result['ok']) {
    flash_set('error', (string) $result['error']);
    redirect('../practice.php');
}

redirect('../practice_run.php?attempt=' . (int) $result['attempt_id']);
