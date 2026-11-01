<?php
/**
 * Record one learner's account of how EduFlex has been for them.
 *
 * Appendix R 7.5, Provide System Feedback. Separate from the request form on
 * the same page: a request is "something is wrong, please look", this is "here
 * is how using it felt", which is what Chapter V needs.
 *
 * Stored through support_create() as an ordinary 'suggestion', so it arrives in
 * the team's Manage Feedback screen with everything else. A second table and a
 * second queue would be a second thing to remember to read.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/support.php';

auth_require_login('../../login.php');
$user = auth_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['_csrf'] ?? null)) {
    flash_set('feedback_error', 'Your session expired. Please try again.');
    redirect('../support.php');
}

$experience = trim((string) ($_POST['experience'] ?? ''));

/* A subject is required by support_create() and by the team's list, and asking
   the learner for one here would turn a two-line box into a form. Dated so a
   learner who sends several can tell them apart in their own list. */
$subject = 'Experience, ' . date('j M Y');

$result = support_create((int) $user['user_id'], 'suggestion', $subject, $experience);

if ($result['ok']) {
    flash_set(
        'feedback_ok',
        'Thank you. Your feedback has been recorded and the project team can read it. '
        . 'It appears in your list above as a suggestion.'
    );
    redirect('../support.php');
}

/* Hand the words back rather than making them type it again. The message
   length rules live in support_create(), so this reports whatever it said. */
flash_set('feedback_draft', $experience);
flash_set(
    'feedback_error',
    (string) ($result['errors']['message']
        ?? $result['errors']['form']
        ?? 'Your feedback could not be recorded. Try again.')
);
redirect('../support.php');
