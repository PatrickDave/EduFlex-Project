<?php
/**
 * Build a mock examination and go straight into it.
 *
 * Learning Activity and Assessment, sub-module 2: Generate Mock Examinations.
 *
 * This is one of the few endpoints that can spend quota, so it is a POST with a
 * CSRF token like every other state change. A GET here would let a page prefetch
 * or a crawler start generating question sets.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/exams.php';

auth_require_login('../../login.php');
$user = auth_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['_csrf'] ?? null)) {
    flash_set('error', 'Your session expired. Please try again.');
    redirect('../practice.php');
}

$result = exam_start((int) $user['user_id']);

if (!$result['ok']) {
    flash_set('error', (string) $result['error']);
    redirect('../practice.php');
}

/* Said plainly because it is the honest account of what just happened, and
   because a learner who sees "0 new questions generated" understands why it was
   instant. */
flash_set('practice_ok', sprintf(
    'Mock examination ready: %d questions across %s. %s',
    (int) $result['items'],
    implode(', ', $result['topics']),
    $result['generated'] === 0
        ? 'All of them came from questions you had not answered yet, so nothing new had to be generated.'
        : sprintf('%d question set%s had to be generated to fill it.',
            (int) $result['generated'], $result['generated'] === 1 ? '' : 's')
));

redirect('../practice_run.php?attempt=' . (int) $result['attempt_id']);
