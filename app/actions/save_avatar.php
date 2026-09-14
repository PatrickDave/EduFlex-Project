<?php
/**
 * Store a learner's profile picture.
 *
 * Validation lives in includes/avatar.php. This file only moves the upload into
 * avatar_store() and turns the result into a flash message.
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

$result = avatar_store((int) $user['user_id'], $_FILES['avatar'] ?? []);

if ($result['ok']) {
    flash_set('avatar_ok', 'Your profile picture has been updated.');
} else {
    flash_set('avatar_error', (string) $result['error']);
}

redirect('../settings.php');
