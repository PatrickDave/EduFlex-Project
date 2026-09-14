<?php
/**
 * EduFlex — notifications.
 *
 * The topbar bell and app/notifications.php read from here. Nothing in this
 * file calls a language model, so notifications cost nothing.
 *
 * Two rules govern every write, and both exist because breaking them is worse
 * than having no notifications at all:
 *
 *   1. A notification must never break the event that caused it. notify()
 *      swallows and logs every failure, the way resource_mark() does. Scoring
 *      an answer is the learner's work; telling them about it is a courtesy.
 *
 *   2. Notifications are written by the action that caused the event, never by
 *      a page render. A write on render would add a row every time anyone
 *      refreshed the screen, and the count would climb on its own.
 *
 * Both rules are asserted in tests/notifications_test.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';

/**
 * The notification types the system writes, and the label the screen shows.
 *
 * The column is a VARCHAR, so this array is the only thing defining the set.
 * An unknown type is stored as 'system' rather than rejected: a typo in a
 * caller should cost the learner a vague label, not a lost notification.
 */
const NOTIFY_TYPES = [
    'system'         => 'System',
    'material_ready' => 'Material read',
    'topics_found'   => 'Topics found',
    'topic_mastered' => 'Topic mastered',
    'recommendation' => 'Recommendation',
];

/** How many rows app/notifications.php shows at once. */
const NOTIFY_PAGE_SIZE = 40;

/* -------------------------------------------------------------------------
   Writing
   ------------------------------------------------------------------------- */

/**
 * Record one notification for one learner.
 *
 * Never throws. Callers are events in the middle of doing real work, and an
 * exception raised here would abort that work.
 *
 * @return bool true when a row was written; false has already been logged
 */
function notify(int $userId, string $type, string $title, string $message): bool
{
    $title   = trim($title);
    $message = trim($message);

    if ($userId <= 0 || $title === '' || $message === '') {
        error_log('EduFlex notify() called with an empty title, message or user.');
        return false;
    }

    if (!isset(NOTIFY_TYPES[$type])) {
        error_log('EduFlex notify() got an unknown type "' . $type . '"; stored as system.');
        $type = 'system';
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO notification (user_id, notification_type, title, message)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $type,
            mb_substr($title, 0, 255),
            mb_substr($message, 0, 2000),
        ]);
        return true;
    } catch (Throwable $e) {
        // Throwable, not PDOException: whatever goes wrong here, the event that
        // called this must still complete.
        error_log('EduFlex notify() failed: ' . $e->getMessage());
        return false;
    }
}

/* -------------------------------------------------------------------------
   Reading

   Every query filters by user_id. A notification is the one place in the
   system where a learner's activity is described in prose, so a leak here
   would be a leak of what somebody else is studying.
   ------------------------------------------------------------------------- */

/**
 * One learner's notifications, newest first.
 *
 * @param string $filter 'all' or 'unread'
 * @return list<array<string,mixed>>
 */
function notifications_list(int $userId, int $limit = NOTIFY_PAGE_SIZE, string $filter = 'all'): array
{
    // LIMIT cannot be bound as a parameter on every driver, so it is clamped
    // and cast to an int here before being interpolated. $filter chooses
    // between two fixed strings and never reaches the SQL as text.
    $limit = (int) max(1, min($limit, 200));
    $where = $filter === 'unread' ? ' AND is_read = 0' : '';

    $stmt = db()->prepare(
        'SELECT notification_id, notification_type, title, message, is_read, created_at
           FROM notification
          WHERE user_id = ?' . $where . '
       ORDER BY created_at DESC, notification_id DESC
          LIMIT ' . $limit
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Unread count for the topbar bell.
 *
 * This runs on every app page, so it stays one COUNT(*) served by the
 * composite index idx_notification_user_read (user_id, is_read) and nothing
 * else. Never throws: a broken bell must not take down every screen.
 */
function notifications_unread_count(int $userId): int
{
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) FROM notification WHERE user_id = ? AND is_read = 0'
        );
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('EduFlex notifications_unread_count() failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Totals for the page header.
 *
 * @return array{total:int, unread:int}
 */
function notifications_totals(int $userId): array
{
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END), 0) AS unread
               FROM notification WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch() ?: [];
        return [
            'total'  => (int) ($row['total'] ?? 0),
            'unread' => (int) ($row['unread'] ?? 0),
        ];
    } catch (Throwable $e) {
        error_log('EduFlex notifications_totals() failed: ' . $e->getMessage());
        return ['total' => 0, 'unread' => 0];
    }
}

/**
 * Mark one notification read.
 *
 * The user_id in the WHERE clause is what stops one learner clearing, and so
 * learning the existence of, another learner's row by guessing an id.
 */
function notifications_mark_read(int $notificationId, int $userId): bool
{
    if ($notificationId <= 0) {
        return false;
    }

    try {
        $stmt = db()->prepare(
            'UPDATE notification SET is_read = 1
              WHERE notification_id = ? AND user_id = ? AND is_read = 0'
        );
        $stmt->execute([$notificationId, $userId]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('EduFlex notifications_mark_read() failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Mark every unread notification for one learner read.
 *
 * @return int how many rows changed
 */
function notifications_mark_all_read(int $userId): int
{
    try {
        $stmt = db()->prepare(
            'UPDATE notification SET is_read = 1 WHERE user_id = ? AND is_read = 0'
        );
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('EduFlex notifications_mark_all_read() failed: ' . $e->getMessage());
        return 0;
    }
}

/* -------------------------------------------------------------------------
   Presentation
   ------------------------------------------------------------------------- */

function notifications_type_label(string $type): string
{
    return NOTIFY_TYPES[$type] ?? 'System';
}

/**
 * Reuse the mastery band colours rather than introduce a second palette.
 */
function notifications_type_band(string $type): string
{
    return match ($type) {
        'topic_mastered' => 'mastered',
        'material_ready', 'topics_found' => 'developing',
        'recommendation' => 'weak',
        default          => 'none',
    };
}

/**
 * "4 minutes ago", or an absolute date once it is more than a week old.
 *
 * An unparseable value falls back to the stored string rather than showing
 * "1 January 1970", which is what strtotime() returning false would produce.
 */
function notifications_when(?string $timestamp): string
{
    if ($timestamp === null || trim($timestamp) === '') {
        return '';
    }

    $then = strtotime($timestamp);
    if ($then === false) {
        return $timestamp;
    }

    // A negative difference means clock skew between PHP and the database,
    // not a notification from the future.
    $seconds = max(0, time() - $then);

    if ($seconds < 60) {
        return 'just now';
    }
    if ($seconds < 3600) {
        return notifications_plural((int) floor($seconds / 60), 'minute') . ' ago';
    }
    if ($seconds < 86400) {
        return notifications_plural((int) floor($seconds / 3600), 'hour') . ' ago';
    }
    if ($seconds < 604800) {
        return notifications_plural((int) floor($seconds / 86400), 'day') . ' ago';
    }

    return date('j M Y', $then);
}

function notifications_plural(int $count, string $noun): string
{
    return $count . ' ' . $noun . ($count === 1 ? '' : 's');
}
