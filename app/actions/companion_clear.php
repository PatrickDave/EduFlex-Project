<?php
/**
 * Forget the conversation.
 *
 * The learner owns what they typed, so they can delete it. This removes only
 * their chat turns; topic detection and generation calls stay in
 * `ai_interaction` because the system's own audit trail is not the learner's
 * conversation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/chat.php';

// One directory deeper than the app pages, so the login path needs a level.
auth_require_login('../../login.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../companion.php');
}
if (!csrf_check($_POST['_csrf'] ?? null)) {
    flash_set('error', 'Your session expired. Please try again.');
    redirect('../companion.php');
}

$user = auth_user();

if (chat_clear((int) $user['user_id'])) {
    flash_set('companion_ok', 'Conversation cleared.');
} else {
    flash_set('error', 'The conversation could not be cleared.');
}

redirect('../companion.php');
