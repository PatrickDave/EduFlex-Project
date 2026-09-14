<?php
/**
 * Remove one uploaded resource, its chunks and its file.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/resources.php';

auth_require_login('../../login.php');
$user = auth_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['_csrf'] ?? null)) {
    flash_set('materials_error', 'Your session expired. Please try again.');
    redirect('../materials.php');
}

$resourceId = (int) ($_POST['resource_id'] ?? 0);

if ($resourceId > 0 && resource_delete($resourceId, (int) $user['user_id'])) {
    flash_set('materials_ok', 'Material deleted.');
} else {
    flash_set('materials_error', 'That material could not be deleted.');
}

redirect('../materials.php');
