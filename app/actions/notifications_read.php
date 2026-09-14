<?php
/**
 * Mark notifications read. One row, or every unread row for this learner.
 *
 * This is a POST, not a link, for two reasons: it changes state, and a GET
 * would let another site clear a learner's bell with an <img> tag.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notifications.php';

auth_require_login('../../login.php');
$user = auth_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['_csrf'] ?? null)) {
    flash_set('notifications_error', 'Your session expired. Please try again.');
    redirect('../notifications.php');
}

$uid = (int) $user['user_id'];

if (($_POST['scope'] ?? '') === 'all') {
    $changed = notifications_mark_all_read($uid);
    flash_set(
        'notifications_ok',
        $changed === 0
            ? 'Nothing was unread.'
            : notifications_plural($changed, 'notification') . ' marked read.'
    );
} else {
    $id = (int) ($_POST['notification_id'] ?? 0);
    if (notifications_mark_read($id, $uid)) {
        flash_set('notifications_ok', 'Marked read.');
    } else {
        // Covers an unknown id, an id belonging to somebody else, and one that
        // was already read. The learner gets the same message for all three, so
        // this endpoint cannot be used to probe which ids exist.
        flash_set('notifications_error', 'That notification could not be updated.');
    }
}

$filter = ($_POST['filter'] ?? '') === 'unread' ? '?filter=unread' : '';
redirect('../notifications.php' . $filter);
