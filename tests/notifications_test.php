<?php
/**
 * EduFlex — notifications test suite.
 *
 *     php tests/notifications_test.php
 *
 * Runs against the mock provider and an in-memory database. No API key, no
 * network, no quota spent.
 *
 * Three things here matter more than the rest:
 *
 *   A notification must never break the event that caused it. If a learner
 *   answers eight questions and the notification insert fails, the score still
 *   has to be recorded. That is asserted by dropping the table and re-running
 *   the event.
 *
 *   topic_mastered must fire on the transition into the band and not on every
 *   recalculation, or a learner who keeps practising a mastered topic gets one
 *   "you have mastered X" per attempt.
 *
 *   Notifications are the one place a learner's activity is described in prose,
 *   so one learner must never be able to read or clear another learner's rows.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/resources.php';
require_once __DIR__ . '/../includes/topics.php';
require_once __DIR__ . '/../includes/attempts.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "  PASS  $label\n"; }
    else            { $failed++; echo "  FAIL  $label\n"; }
}
function section(string $name): void { echo "\n$name\n"; }

echo "EduFlex notifications suite\n===========================\n";

/* ----------------------------------------------------------- test database */

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("
CREATE TABLE notification (
  notification_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
  notification_type TEXT NOT NULL, title TEXT NOT NULL, message TEXT NOT NULL,
  is_read INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE learning_resource (
  resource_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
  title TEXT NOT NULL, file_type TEXT NOT NULL DEFAULT 'txt',
  storage_path TEXT NOT NULL DEFAULT '', original_name TEXT NULL, file_size INTEGER NOT NULL DEFAULT 0,
  processing_status TEXT NOT NULL DEFAULT 'processed', extract_message TEXT NULL,
  extract_engine TEXT NULL, char_count INTEGER NOT NULL DEFAULT 0,
  chunk_count INTEGER NOT NULL DEFAULT 0, uploaded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at TEXT NULL);
CREATE TABLE resource_chunk (
  chunk_id INTEGER PRIMARY KEY AUTOINCREMENT, resource_id INTEGER NOT NULL,
  chunk_index INTEGER NOT NULL, content TEXT NOT NULL, word_count INTEGER NOT NULL DEFAULT 0);
CREATE TABLE topic_progress (
  topic_progress_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
  resource_id INTEGER NOT NULL, topic_name TEXT NOT NULL,
  mastery_score REAL NOT NULL DEFAULT 0, weakness_priority TEXT NOT NULL DEFAULT 'none',
  scored_items INTEGER NOT NULL DEFAULT 0);
CREATE TABLE learning_activity (
  activity_id INTEGER PRIMARY KEY AUTOINCREMENT, resource_id INTEGER NOT NULL,
  topic_progress_id INTEGER NULL, activity_type TEXT NOT NULL, title TEXT NOT NULL,
  bloom_level TEXT NOT NULL, difficulty_level TEXT NOT NULL,
  generated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE activity_item (
  item_id INTEGER PRIMARY KEY AUTOINCREMENT, activity_id INTEGER NOT NULL,
  question_text TEXT NOT NULL, item_type TEXT NOT NULL, options_json TEXT NULL,
  correct_answer TEXT NOT NULL, explanation TEXT NULL,
  topic_progress_id INTEGER NULL, bloom_level TEXT NULL);
CREATE TABLE activity_attempt (
  attempt_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
  activity_id INTEGER NOT NULL, score REAL NULL, total_items INTEGER NOT NULL DEFAULT 0,
  started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at TEXT NULL);
CREATE TABLE attempt_response (
  response_id INTEGER PRIMARY KEY AUTOINCREMENT, attempt_id INTEGER NOT NULL,
  item_id INTEGER NOT NULL, user_answer TEXT NULL, is_correct INTEGER NOT NULL DEFAULT 0,
  answered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE recommendation (
  recommendation_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
  topic_progress_id INTEGER NOT NULL, recommended_level TEXT NOT NULL,
  recommended_activity TEXT NOT NULL, reason TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'new', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE ai_interaction (
  interaction_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
  resource_id INTEGER NULL, prompt TEXT NOT NULL, response TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
");
db_set_connection($pdo);

/** How many notifications of one type a learner holds. */
$countOf = static function (int $userId, ?string $type = null) use ($pdo): int {
    $sql = 'SELECT COUNT(*) FROM notification WHERE user_id = ?';
    $params = [$userId];
    if ($type !== null) {
        $sql .= ' AND notification_type = ?';
        $params[] = $type;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
};

/* -------------------------------------------------------------- notify() */

section('Writing a notification');

check('a notification is written',
    notify(1, 'system', 'Hello', 'A message long enough to be real.') === true);
check('and it is stored against that learner', $countOf(1) === 1);

check('an empty title is refused',   notify(1, 'system', '   ', 'Body text here.') === false);
check('an empty message is refused', notify(1, 'system', 'Title', '  ') === false);
check('a user id of zero is refused', notify(0, 'system', 'Title', 'Body text here.') === false);
check('none of those wrote a row',   $countOf(1) === 1);

check('an unknown type is still delivered',
    notify(1, 'not_a_real_type', 'Odd one', 'Stored anyway, as a system notice.') === true);
check('and it is stored as system', $countOf(1, 'system') === 2);

check('an over-long title is truncated rather than rejected', (function () use ($pdo) {
    notify(1, 'system', str_repeat('A', 400), 'Body text that is long enough.');
    $len = (int) $pdo->query('SELECT LENGTH(title) FROM notification
                               ORDER BY notification_id DESC LIMIT 1')->fetchColumn();
    return $len === 255;
})());

$pdo->exec('DELETE FROM notification');

/* --------------------------------------------------- event 1: material read */

section('Event: a document finished processing');

/* resource_process() reads a real file from disk, so the suite writes one and
   removes it afterwards. EXTRACT_MIN_CHARS is 200, so the text has to be a
   genuine paragraph rather than a sentence. */
$storageDir = __DIR__ . '/../storage/uploads';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0775, true);
}
$tempName = 'test_notifications_' . bin2hex(random_bytes(6)) . '.txt';
$tempPath = $storageDir . '/' . $tempName;
file_put_contents($tempPath, str_repeat(
    'A Fourier series decomposes a periodic signal into a sum of sinusoids, and '
    . 'the harmonic content of that sum determines the shape of the waveform. ', 6
));

$pdo->prepare("INSERT INTO learning_resource
    (user_id, title, file_type, storage_path, original_name, processing_status)
    VALUES (1, 'Signals Reviewer', 'txt', ?, 'signals.txt', 'pending')")
    ->execute(['storage/uploads/' . $tempName]);
$resourceId = (int) $pdo->lastInsertId();

$processed = resource_process($resourceId, 1);
check('the document was read',                 $processed['ok'] === true);
check('one material_ready notification',       $countOf(1, 'material_ready') === 1);
check('it names the material', (function () use ($pdo) {
    $title = (string) $pdo->query("SELECT title FROM notification
                                    WHERE notification_type = 'material_ready'
                                 ORDER BY notification_id DESC LIMIT 1")->fetchColumn();
    return str_contains($title, 'Signals Reviewer');
})());
check('and it reports how many sections were stored', (function () use ($pdo, $processed) {
    $msg = (string) $pdo->query("SELECT message FROM notification
                                  WHERE notification_type = 'material_ready'
                               ORDER BY notification_id DESC LIMIT 1")->fetchColumn();
    return str_contains($msg, (string) $processed['chunks']);
})());

check('a read that fails writes nothing', (function () use ($pdo, $countOf) {
    // A resource whose file is not on disk. Extraction fails, so there is
    // nothing to tell the learner about.
    $pdo->exec("INSERT INTO learning_resource
        (user_id, title, file_type, storage_path, processing_status)
        VALUES (1, 'Missing file', 'txt', 'storage/uploads/does_not_exist.txt', 'pending')");
    $before = $countOf(1, 'material_ready');
    $result = resource_process((int) $pdo->lastInsertId(), 1);
    return $result['ok'] === false && $countOf(1, 'material_ready') === $before;
})());

/* ------------------------------------------------- event 2: topics detected */

section('Event: topic detection found topics');

ai_provider(new MockProvider());
$detected = topics_detect($resourceId, 1);
check('detection succeeded',              $detected['ok'] === true);
check('it inserted topics',               $detected['new'] > 0);
check('one topics_found notification',    $countOf(1, 'topics_found') === 1);

$again = topics_detect($resourceId, 1);
check('re-running finds nothing new',     $again['new'] === 0);
check('and writes no second notification', $countOf(1, 'topics_found') === 1);

/* --------------------------------------------- event 3: a topic is mastered */

section('Event: a topic reached the mastered band');

/* A dedicated topic and activity, so the mastery arithmetic is controlled
   rather than inherited from whatever detection happened to name. */
$pdo->exec("INSERT INTO topic_progress (user_id, resource_id, topic_name)
            VALUES (1, " . $resourceId . ", 'Controlled Topic')");
$topicId = (int) $pdo->lastInsertId();

$pdo->prepare("INSERT INTO learning_activity
    (resource_id, topic_progress_id, activity_type, title, bloom_level, difficulty_level)
    VALUES (?, ?, 'practice_set', 'Remember set', 'Remember', 'easy')")
    ->execute([$resourceId, $topicId]);
$activityId = (int) $pdo->lastInsertId();

$pdo->prepare('INSERT INTO activity_attempt (user_id, activity_id, total_items)
               VALUES (1, ?, 6)')->execute([$activityId]);
$attemptId = (int) $pdo->lastInsertId();

/** Record one answer against the controlled activity. */
$answer = static function (int $index, int $correct) use ($pdo, $activityId, $attemptId): void {
    $pdo->prepare('INSERT INTO activity_item
        (activity_id, question_text, item_type, correct_answer)
        VALUES (?, ?, ?, ?)')
        ->execute([$activityId, "Question $index of the controlled set", 'multiple_choice', 'A']);
    $itemId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO attempt_response (attempt_id, item_id, user_answer, is_correct)
                   VALUES (?, ?, ?, ?)')
        ->execute([$attemptId, $itemId, 'A', $correct]);
};

// Five wrong answers: enough scored items to leave "No data", but weak.
for ($i = 1; $i <= 5; $i++) { $answer($i, 0); }
$weak = mastery_recalculate($topicId, 1);
check('the topic is weak',                 $weak['band'] === 'weak');
check('no topic_mastered notification yet', $countOf(1, 'topic_mastered') === 0);

// Enough correct answers on top that the recency weighting carries it over 85.
for ($i = 6; $i <= 40; $i++) { $answer($i, 1); }
$mastered = mastery_recalculate($topicId, 1);
check('the topic is now mastered',         $mastered['band'] === 'mastered');
check('the previous band is reported',     $mastered['previous_band'] === 'weak');
check('one topic_mastered notification',   $countOf(1, 'topic_mastered') === 1);
check('it names the topic', (function () use ($pdo) {
    $title = (string) $pdo->query("SELECT title FROM notification
                                    WHERE notification_type = 'topic_mastered'
                                 ORDER BY notification_id DESC LIMIT 1")->fetchColumn();
    return str_contains($title, 'Controlled Topic');
})());

section('topic_mastered fires on the transition only');

// The same figure recalculated four more times. This is what happens in
// practice: mastery_recalculate() runs after every finished attempt.
for ($i = 0; $i < 4; $i++) {
    $repeat = mastery_recalculate($topicId, 1);
}
check('recalculating a mastered topic still reports mastered', $repeat['band'] === 'mastered');
check('and writes no further notification', $countOf(1, 'topic_mastered') === 1);

check('dropping out of the band and climbing back notifies again',
    (function () use ($pdo, $topicId, $countOf, $answer) {
        // Twelve wrong answers are recent, so they dominate the weighting.
        for ($i = 100; $i < 112; $i++) { $answer($i, 0); }
        $dropped = mastery_recalculate($topicId, 1);
        if ($dropped['band'] === 'mastered') {
            return false;   // the fixture failed to drop it; the test proves nothing
        }
        for ($i = 200; $i < 260; $i++) { $answer($i, 1); }
        $back = mastery_recalculate($topicId, 1);
        return $back['band'] === 'mastered' && $countOf(1, 'topic_mastered') === 2;
    })());

/* --------------------------------------------- event 4: a recommendation */

section('Event: a new recommendation was created');

$pdo->exec('DELETE FROM notification');
$pdo->exec('DELETE FROM recommendation');

/* A second topic with enough evidence to be recommended, and worse than the
   mastered one, so recommendation_refresh() has somewhere to point. */
$pdo->exec("INSERT INTO topic_progress
    (user_id, resource_id, topic_name, mastery_score, scored_items, weakness_priority)
    VALUES (1, " . $resourceId . ", 'Weak Topic', 41.0, 9, 'weak')");
$weakTopicId = (int) $pdo->lastInsertId();

$reco = recommendation_refresh(1);
check('a recommendation was created',     $reco['ok'] === true);
check('it points at the weakest topic',   $reco['topic'] === 'Weak Topic');
check('one recommendation notification',  $countOf(1, 'recommendation') === 1);
check('it names the topic', (function () use ($pdo) {
    $title = (string) $pdo->query("SELECT title FROM notification
                                    WHERE notification_type = 'recommendation'
                                 ORDER BY notification_id DESC LIMIT 1")->fetchColumn();
    return str_contains($title, 'Weak Topic');
})());

$same = recommendation_refresh(1);
check('refreshing writes a fresh recommendation row', $same['ok'] === true);
check('but the same topic does not notify twice',     $countOf(1, 'recommendation') === 1);

check('a recommendation for a different topic does notify',
    (function () use ($pdo, $resourceId, $countOf) {
        // A worse topic appears, so EduFlex changes its mind. That is news.
        $pdo->exec("INSERT INTO topic_progress
            (user_id, resource_id, topic_name, mastery_score, scored_items, weakness_priority)
            VALUES (1, " . $resourceId . ", 'Weaker Topic', 12.0, 11, 'weak')");
        $moved = recommendation_refresh(1);
        return $moved['topic'] === 'Weaker Topic' && $countOf(1, 'recommendation') === 2;
    })());

/* ------------------------------- a failed write does not break the event */

section('A failed notification does not break its event');

$pdo->exec('DROP TABLE notification');

check('mastery is still recalculated with no notification table',
    (function () use ($pdo, $topicId) {
        $result = mastery_recalculate($topicId, 1);
        $stored = (float) $pdo->query('SELECT mastery_score FROM topic_progress
                                        WHERE topic_progress_id = ' . $topicId)->fetchColumn();
        return $result['mastery'] > 0.0 && abs($stored - $result['mastery']) < 0.01;
    })());

check('a recommendation is still created',
    (function () use ($pdo) {
        $before = (int) $pdo->query('SELECT COUNT(*) FROM recommendation')->fetchColumn();
        $result = recommendation_refresh(1);
        $after  = (int) $pdo->query('SELECT COUNT(*) FROM recommendation')->fetchColumn();
        return $result['ok'] === true && $after === $before + 1;
    })());

check('topic detection still stores its topics',
    (function () use ($pdo, $resourceId) {
        $pdo->exec('DELETE FROM topic_progress');
        ai_provider(new MockProvider());
        $result = topics_detect($resourceId, 1);
        $rows = (int) $pdo->query('SELECT COUNT(*) FROM topic_progress')->fetchColumn();
        return $result['ok'] === true && $result['new'] > 0 && $rows === $result['new'];
    })());

check('notify() itself reports the failure rather than throwing',
    notify(1, 'system', 'Into the void', 'There is no table to write this to.') === false);

check('the unread count degrades to zero rather than throwing',
    notifications_unread_count(1) === 0);
check('the totals degrade to zero as well',
    notifications_totals(1) === ['total' => 0, 'unread' => 0]);
check('marking read reports failure rather than throwing',
    notifications_mark_read(1, 1) === false);
check('marking all read reports zero rather than throwing',
    notifications_mark_all_read(1) === 0);

@unlink($tempPath);

/* ------------------------------------------- reading, counting, ownership */

section('Reading is scoped to one learner');

$pdo->exec("
CREATE TABLE notification (
  notification_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
  notification_type TEXT NOT NULL, title TEXT NOT NULL, message TEXT NOT NULL,
  is_read INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
");

notify(1, 'system', 'Learner one, first', 'Body text for the first learner.');
notify(1, 'system', 'Learner one, second', 'Body text for the first learner.');
notify(1, 'system', 'Learner one, third', 'Body text for the first learner.');
notify(2, 'system', 'Learner two, only', 'Body text for the second learner.');

check('each learner sees only their own rows',
    count(notifications_list(1)) === 3 && count(notifications_list(2)) === 1);
check('the unread count is per learner',
    notifications_unread_count(1) === 3 && notifications_unread_count(2) === 1);
check('the totals are per learner',
    notifications_totals(1) === ['total' => 3, 'unread' => 3]);
check('a learner with nothing counts zero', notifications_unread_count(999) === 0);

check('the newest row comes first', (function () {
    $rows = notifications_list(1);
    return (string) $rows[0]['title'] === 'Learner one, third';
})());

check('the limit is respected',    count(notifications_list(1, 2)) === 2);
check('a limit of zero is clamped to one', count(notifications_list(1, 0)) === 1);

section('Marking read is scoped to one learner');

$ownId = (int) $pdo->query("SELECT notification_id FROM notification
                             WHERE user_id = 1 ORDER BY notification_id ASC LIMIT 1")->fetchColumn();
$otherId = (int) $pdo->query("SELECT notification_id FROM notification
                               WHERE user_id = 2 LIMIT 1")->fetchColumn();

check('a learner can mark their own row read', notifications_mark_read($ownId, 1) === true);
check('the unread count drops',                notifications_unread_count(1) === 2);
check('marking the same row again reports nothing changed',
    notifications_mark_read($ownId, 1) === false);

check('a learner cannot mark another learner\'s row read',
    notifications_mark_read($otherId, 1) === false);
check('and that row is still unread',          notifications_unread_count(2) === 1);
check('an unknown id is refused',              notifications_mark_read(999999, 1) === false);
check('an id of zero is refused',              notifications_mark_read(0, 1) === false);

check('the unread filter returns only unread rows', (function () {
    $rows = notifications_list(1, 40, 'unread');
    $allUnread = true;
    foreach ($rows as $r) {
        if ((int) $r['is_read'] !== 0) { $allUnread = false; }
    }
    return count($rows) === 2 && $allUnread;
})());

check('marking all read clears this learner only', (function () {
    $changed = notifications_mark_all_read(1);
    return $changed === 2
        && notifications_unread_count(1) === 0
        && notifications_unread_count(2) === 1;
})());
check('marking all read again changes nothing', notifications_mark_all_read(1) === 0);
check('the rows are still there to read',       count(notifications_list(1)) === 3);

/* ------------------------------------------------------------ presentation */

section('Presentation helpers');

check('a known type has a label',   notifications_type_label('topic_mastered') === 'Topic mastered');
check('an unknown type falls back', notifications_type_label('nonsense') === 'System');
check('mastered uses the mastered band',
    notifications_type_band('topic_mastered') === 'mastered');
check('an unknown type uses the neutral band',
    notifications_type_band('nonsense') === 'none');
check('every declared type has a band', (function () {
    foreach (array_keys(NOTIFY_TYPES) as $type) {
        if (!in_array(notifications_type_band($type), ['mastered','developing','weak','none'], true)) {
            return false;
        }
    }
    return true;
})());

check('a fresh timestamp reads as just now',
    notifications_when(date('Y-m-d H:i:s')) === 'just now');
check('minutes are pluralised',
    notifications_when(date('Y-m-d H:i:s', time() - 120)) === '2 minutes ago');
check('one minute is singular',
    notifications_when(date('Y-m-d H:i:s', time() - 61)) === '1 minute ago');
check('hours are reported',
    notifications_when(date('Y-m-d H:i:s', time() - 7200)) === '2 hours ago');
check('days are reported',
    notifications_when(date('Y-m-d H:i:s', time() - 172800)) === '2 days ago');
check('anything older than a week becomes a date',
    notifications_when(date('Y-m-d H:i:s', time() - 1209600))
        === date('j M Y', time() - 1209600));
check('a clock ahead of the database does not read as the future',
    notifications_when(date('Y-m-d H:i:s', time() + 600)) === 'just now');
check('an empty timestamp yields an empty string', notifications_when('') === '');
check('a null timestamp yields an empty string',   notifications_when(null) === '');
check('an unparseable timestamp is echoed back rather than shown as 1970',
    notifications_when('not a date') === 'not a date');

check('plurals are formed correctly',
    notifications_plural(1, 'notification') === '1 notification'
    && notifications_plural(0, 'notification') === '0 notifications'
    && notifications_plural(7, 'notification') === '7 notifications');

echo "\n===========================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
