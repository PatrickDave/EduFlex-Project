<?php
/**
 * Remove a learner's profile picture, from the database and from disk.
 *
 * Separate from save_avatar.php rather than folded into it behind a flag, so
 * the thing that deletes a file is its own endpoint with its own name, the way
 * delete_resource.php is.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/avatar.php';

auth_require_login('../../login.php');
$user = auth_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['_csrf'] ?? null)) {
    flash_set('avatar_error', 'Your session expired. Please try again.');
    redirect('../settings.php');
}

if (avatar_remove((int) $user['user_id'])) {
    flash_set('avatar_ok', 'Your profile picture has been removed. Your initials are shown instead.');
} else {
    flash_set('avatar_error', 'There was no profile picture to remove.');
}

redirect('../settings.php');
