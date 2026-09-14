<?php
/**
 * Practise what EduFlex recommends, in one press.
 *
 * This is the adaptive claim made usable. The learner does not have to read a
 * recommendation, remember the topic, find it in a list and generate a set:
 * the button takes them straight into questions on the topic the system says
 * they are weakest at.
 *
 * A plain form post rather than AJAX, because it ends in a navigation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/attempts.php';

// One directory deeper than the app pages, so the login path needs a level.
auth_require_login('../../login.php');

// Generating a set when none is stored is a provider call.
@set_time_limit(180);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../practice.php');
}
if (!csrf_check($_POST['_csrf'] ?? null)) {
    flash_set('error', 'Your session expired. Please try again.');
    redirect('../practice.php');
}

$user = auth_user();
$uid  = (int) $user['user_id'];

// An explicit topic wins; otherwise the system chooses.
$topicId = (int) ($_POST['topic_progress_id'] ?? 0);

if ($topicId <= 0) {
    $next = practice_next_topic($uid);
    if (!$next['ok']) {
        flash_set('error', (string) $next['error']);
        redirect('../practice.php');
    }
    $topicId = (int) $next['topic_progress_id'];
}

try {
    $result = practice_start_topic($topicId, $uid);
} catch (Throwable $e) {
    error_log('EduFlex practice_next failed: ' . $e->getMessage());
    flash_set('error', 'The practice set could not be prepared. '
        . 'Check the PHP error log.');
    redirect('../practice.php');
}

if (!$result['ok']) {
    flash_set('error', (string) $result['error']);
    redirect('../practice.php');
}

redirect('../practice_run.php?attempt=' . (int) $result['attempt_id']);
