<?php
/**
 * EduFlex — settings test suite: change password, export, delete account.
 *
 *     php tests/settings_test.php
 *
 * Runs against an in-memory database. No API key, no network, no server.
 *
 * The deletion test is the one that matters most. Chapter III promises a
 * participant can withdraw and have their data removed, so this suite proves
 * that one call removes every row belonging to that learner and not one row
 * belonging to anybody else. The foreign keys here mirror the ON DELETE rules
 * in database/01_schema.sql, and SQLite only enforces them with the pragma
 * below, which is why it is set explicitly.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "  PASS  $label\n"; }
    else            { $failed++; echo "  FAIL  $label\n"; }
}
function section(string $name): void { echo "\n$name\n"; }

echo "EduFlex settings suite\n======================\n";

/* ----------------------------------------------------------- test database */

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
// Without this SQLite parses foreign keys and then ignores them, and the
// deletion test below would pass while proving nothing.
$pdo->exec('PRAGMA foreign_keys = ON');

$pdo->exec("
CREATE TABLE user (
  user_id INTEGER PRIMARY KEY AUTOINCREMENT, full_name TEXT NOT NULL,
  email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL,
  program TEXT NOT NULL DEFAULT '', year_level INTEGER NOT NULL DEFAULT 1,
  account_status TEXT NOT NULL DEFAULT 'active', avatar_path TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE subscription (
  subscription_id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES user(user_id) ON DELETE CASCADE,
  plan_type TEXT NOT NULL, status TEXT NOT NULL);
CREATE TABLE notification (
  notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES user(user_id) ON DELETE CASCADE,
  notification_type TEXT NOT NULL, title TEXT NOT NULL, message TEXT NOT NULL,
  is_read INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE support_request (
  request_id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES user(user_id) ON DELETE CASCADE,
  request_type TEXT NOT NULL, subject TEXT NOT NULL, message TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'open',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE learning_resource (
  resource_id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES user(user_id) ON DELETE CASCADE,
  title TEXT NOT NULL, original_name TEXT NULL, file_type TEXT NOT NULL DEFAULT 'txt',
  file_size INTEGER NOT NULL DEFAULT 0, storage_path TEXT NOT NULL DEFAULT '',
  processing_status TEXT NOT NULL DEFAULT 'processed',
  char_count INTEGER NOT NULL DEFAULT 0, chunk_count INTEGER NOT NULL DEFAULT 0,
  uploaded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, processed_at TEXT NULL);
CREATE TABLE resource_chunk (
  chunk_id INTEGER PRIMARY KEY AUTOINCREMENT,
  resource_id INTEGER NOT NULL REFERENCES learning_resource(resource_id) ON DELETE CASCADE,
  chunk_index INTEGER NOT NULL, content TEXT NOT NULL, word_count INTEGER NOT NULL DEFAULT 0);
CREATE TABLE topic_progress (
  topic_progress_id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES user(user_id) ON DELETE CASCADE,
  resource_id INTEGER NOT NULL REFERENCES learning_resource(resource_id) ON DELETE CASCADE,
  topic_name TEXT NOT NULL, mastery_score REAL NOT NULL DEFAULT 0,
  weakness_priority TEXT NOT NULL DEFAULT 'none', scored_items INTEGER NOT NULL DEFAULT 0);
CREATE TABLE learning_activity (
  activity_id INTEGER PRIMARY KEY AUTOINCREMENT,
  resource_id INTEGER NOT NULL REFERENCES learning_resource(resource_id) ON DELETE CASCADE,
  topic_progress_id INTEGER NULL REFERENCES topic_progress(topic_progress_id) ON DELETE SET NULL,
  activity_type TEXT NOT NULL, title TEXT NOT NULL, bloom_level TEXT NOT NULL,
  difficulty_level TEXT NOT NULL, generated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE activity_item (
  item_id INTEGER PRIMARY KEY AUTOINCREMENT,
  activity_id INTEGER NOT NULL REFERENCES learning_activity(activity_id) ON DELETE CASCADE,
  question_text TEXT NOT NULL, item_type TEXT NOT NULL, options_json TEXT NULL,
  correct_answer TEXT NOT NULL, explanation TEXT NULL,
  topic_progress_id INTEGER NULL, bloom_level TEXT NULL);
CREATE TABLE activity_attempt (
  attempt_id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES user(user_id) ON DELETE CASCADE,
  activity_id INTEGER NOT NULL REFERENCES learning_activity(activity_id) ON DELETE CASCADE,
  score REAL NULL, total_items INTEGER NOT NULL DEFAULT 0,
  started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at TEXT NULL);
CREATE TABLE attempt_response (
  response_id INTEGER PRIMARY KEY AUTOINCREMENT,
  attempt_id INTEGER NOT NULL REFERENCES activity_attempt(attempt_id) ON DELETE CASCADE,
  item_id INTEGER NOT NULL REFERENCES activity_item(item_id) ON DELETE CASCADE,
  user_answer TEXT NULL, is_correct INTEGER NOT NULL DEFAULT 0,
  answered_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE recommendation (
  recommendation_id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES user(user_id) ON DELETE CASCADE,
  topic_progress_id INTEGER NOT NULL REFERENCES topic_progress(topic_progress_id) ON DELETE CASCADE,
  recommended_level TEXT NOT NULL, recommended_activity TEXT NOT NULL,
  reason TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'new',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE ai_interaction (
  interaction_id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES user(user_id) ON DELETE CASCADE,
  resource_id INTEGER NULL REFERENCES learning_resource(resource_id) ON DELETE SET NULL,
  prompt TEXT NOT NULL, response TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
");
db_set_connection($pdo);

/**
 * Give one learner a complete set of rows in every table that hangs off them.
 */
function seed_learner(PDO $pdo, string $name, string $email, string $password): int
{
    $pdo->prepare('INSERT INTO user (full_name, email, password_hash, program)
                   VALUES (?, ?, ?, ?)')
        ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'BSIT']);
    $userId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO subscription (user_id, plan_type, status) VALUES (?, ?, ?)')
        ->execute([$userId, 'free', 'active']);
    $pdo->prepare('INSERT INTO notification (user_id, notification_type, title, message)
                   VALUES (?, ?, ?, ?)')
        ->execute([$userId, 'system', 'Welcome', 'A message for ' . $name . '.']);
    $pdo->prepare('INSERT INTO support_request (user_id, request_type, subject, message)
                   VALUES (?, ?, ?, ?)')
        ->execute([$userId, 'question', 'How does mastery work', 'Asked by ' . $name . '.']);

    $pdo->prepare("INSERT INTO learning_resource
        (user_id, title, original_name, storage_path, char_count, chunk_count)
        VALUES (?, ?, ?, ?, 900, 2)")
        ->execute([$userId, $name . ' Reviewer', 'reviewer.txt', 'storage/uploads/nothing_here.txt']);
    $resourceId = (int) $pdo->lastInsertId();

    foreach ([0, 1] as $i) {
        $pdo->prepare('INSERT INTO resource_chunk (resource_id, chunk_index, content, word_count)
                       VALUES (?, ?, ?, ?)')
            ->execute([$resourceId, $i, 'Chunk ' . $i . ' belonging to ' . $name . '.', 6]);
    }

    $pdo->prepare('INSERT INTO topic_progress
        (user_id, resource_id, topic_name, mastery_score, scored_items, weakness_priority)
        VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$userId, $resourceId, $name . ' Topic', 64.5, 8, 'weak']);
    $topicId = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO learning_activity
        (resource_id, topic_progress_id, activity_type, title, bloom_level, difficulty_level)
        VALUES (?, ?, 'practice_set', ?, 'Remember', 'easy')")
        ->execute([$resourceId, $topicId, $name . ' set']);
    $activityId = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO activity_item
        (activity_id, question_text, item_type, options_json, correct_answer)
        VALUES (?, ?, 'multiple_choice', ?, 'A')")
        ->execute([$activityId, 'A question written for ' . $name, json_encode(['A','B','C','D'])]);
    $itemId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO activity_attempt (user_id, activity_id, score, total_items, completed_at)
                   VALUES (?, ?, 75.0, 1, CURRENT_TIMESTAMP)')
        ->execute([$userId, $activityId]);
    $attemptId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO attempt_response (attempt_id, item_id, user_answer, is_correct)
                   VALUES (?, ?, ?, 1)')
        ->execute([$attemptId, $itemId, 'A']);

    $pdo->prepare("INSERT INTO recommendation
        (user_id, topic_progress_id, recommended_level, recommended_activity, reason)
        VALUES (?, ?, 'Understand', ?, ?)")
        ->execute([$userId, $topicId, $name . ' next set', 'Because it is weakest.']);

    $pdo->prepare('INSERT INTO ai_interaction (user_id, resource_id, prompt, response)
                   VALUES (?, ?, ?, ?)')
        ->execute([$userId, $resourceId, 'A prompt for ' . $name, 'A reply for ' . $name]);

    return $userId;
}

/** Every table that holds learner-scoped rows, and how to count one learner's. */
const OWNED_TABLES = [
    'user'              => 'user_id',
    'subscription'      => 'user_id',
    'notification'      => 'user_id',
    'support_request'   => 'user_id',
    'learning_resource' => 'user_id',
    'topic_progress'    => 'user_id',
    'activity_attempt'  => 'user_id',
    'recommendation'    => 'user_id',
    'ai_interaction'    => 'user_id',
];

/** @return array<string,int> rows per table for one learner */
function owned_counts(PDO $pdo, int $userId): array
{
    $counts = [];
    foreach (OWNED_TABLES as $table => $column) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $column = ?");
        $stmt->execute([$userId]);
        $counts[$table] = (int) $stmt->fetchColumn();
    }
    return $counts;
}

$alice = seed_learner($pdo, 'Alice', 'alice@example.com', 'correct horse battery');
$bob   = seed_learner($pdo, 'Bob',   'bob@example.com',   'another good password');

/* ------------------------------------------------------ the shared rule */

section('One password rule, shared by both forms');

check('a short password is refused',   auth_password_error('short') !== null);
check('an empty password is refused',  auth_password_error('') !== null);
check('a long enough one is accepted', auth_password_error('12345678') === null);
check('the message names the minimum',
    str_contains((string) auth_password_error('a'), (string) AUTH_PASSWORD_MIN));
check('registration and this form use the same rule',
    auth_register('Carl', 'carl@example.com', 'short', true)['errors']['password']
        === auth_password_error('short'));

/* --------------------------------------------------------- change password */

section('A wrong current password changes nothing');

$before = (string) $pdo->query("SELECT password_hash FROM user WHERE user_id = $alice")->fetchColumn();

$wrong = auth_change_password($alice, 'not my password', 'a brand new password', 'a brand new password');
check('the change is refused',            $wrong['ok'] === false);
check('the error is on the current field', isset($wrong['errors']['current_password']));
check('the stored hash is untouched',
    (string) $pdo->query("SELECT password_hash FROM user WHERE user_id = $alice")->fetchColumn()
        === $before);
check('the old password still works',
    password_verify('correct horse battery', $before));

section('Validation before anything is touched');

check('an empty current password is refused',
    isset(auth_change_password($alice, '', 'a brand new password', 'a brand new password')['errors']['current_password']));
check('a short new password is refused',
    isset(auth_change_password($alice, 'correct horse battery', 'abc', 'abc')['errors']['new_password']));
check('a mismatched confirmation is refused',
    isset(auth_change_password($alice, 'correct horse battery', 'a brand new password', 'a different one')['errors']['confirm_password']));
check('reusing the current password is refused',
    isset(auth_change_password($alice, 'correct horse battery', 'correct horse battery', 'correct horse battery')['errors']['new_password']));
check('none of those changed the hash',
    (string) $pdo->query("SELECT password_hash FROM user WHERE user_id = $alice")->fetchColumn()
        === $before);
check('an unknown account is refused',
    auth_change_password(999999, 'anything at all', 'a brand new password', 'a brand new password')['ok'] === false);

section('A successful change invalidates the old password');

$ok = auth_change_password($alice, 'correct horse battery', 'a brand new password', 'a brand new password');
check('the change succeeds',      $ok['ok'] === true);
check('no errors are reported',   $ok['errors'] === []);

$after = (string) $pdo->query("SELECT password_hash FROM user WHERE user_id = $alice")->fetchColumn();
check('the stored hash changed',  $after !== $before);
check('the new password verifies',      password_verify('a brand new password', $after));
check('the old password no longer does', !password_verify('correct horse battery', $after));
check('the new password is hashed, not stored in the clear',
    !str_contains($after, 'a brand new password'));
check('the old password is refused as the current one from now on',
    auth_change_password($alice, 'correct horse battery', 'yet another password', 'yet another password')['ok'] === false);
check('nobody else was affected',
    password_verify('another good password',
        (string) $pdo->query("SELECT password_hash FROM user WHERE user_id = $bob")->fetchColumn()));
check('the change is announced to the learner',
    (int) $pdo->query("SELECT COUNT(*) FROM notification
                        WHERE user_id = $alice AND title = 'Your password was changed'")
        ->fetchColumn() === 1);

/* ------------------------------------------------------------------ export */

section('Export my data');

$export = auth_export_data($alice);

check('the account is included',        ($export['account']['email'] ?? null) === 'alice@example.com');
check('the password hash is not',       !array_key_exists('password_hash', $export['account'] ?? []));
check('materials are included',         count($export['materials']) === 1);
check('topics are included',            count($export['topics']) === 1);
check('attempts are included',          count($export['attempts']) === 1);
check('answers are included',           count($export['answers']) === 1);
check('a topic carries its mastery score',
    (float) $export['topics'][0]['mastery_score'] === 64.5);
check('an attempt carries its topic name',
    (string) $export['attempts'][0]['topic_name'] === 'Alice Topic');
check('an answer carries whether it was correct',
    (int) $export['answers'][0]['is_correct'] === 1);
check('the export is dated',            !empty($export['exported_at']));

check('nothing belonging to anybody else is in it', (function () use ($export) {
    $json = json_encode($export);
    return $json !== false
        && !str_contains($json, 'Bob')
        && !str_contains($json, 'bob@example.com');
})());

check('it encodes as JSON cleanly',
    json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) !== false);

check('a learner with nothing exports an empty but valid structure', (function () use ($pdo) {
    $pdo->exec("INSERT INTO user (full_name, email, password_hash)
                VALUES ('Empty', 'empty@example.com', 'x')");
    $empty = auth_export_data((int) $pdo->lastInsertId());
    return $empty['materials'] === []
        && $empty['topics'] === []
        && $empty['attempts'] === []
        && $empty['answers'] === []
        && ($empty['account']['email'] ?? null) === 'empty@example.com';
})());

/* ------------------------------------------------------------------ delete */

section('The wrong confirmation deletes nothing');

$aliceBefore = owned_counts($pdo, $alice);
$bobBefore   = owned_counts($pdo, $bob);

check('an empty confirmation is refused',
    auth_delete_account($alice, '')['ok'] === false);
check('somebody else\'s email is refused',
    auth_delete_account($alice, 'bob@example.com')['ok'] === false);
check('a near miss is refused',
    auth_delete_account($alice, 'alice@example.co')['ok'] === false);
check('an unknown account is refused',
    auth_delete_account(999999, 'alice@example.com')['ok'] === false);
check('every row is still there',        owned_counts($pdo, $alice) === $aliceBefore);
check('and so is everybody else\'s',     owned_counts($pdo, $bob) === $bobBefore);

section('Deleting removes everything for that learner');

check('a different case in the typed email is accepted', (function () use ($pdo) {
    // Emails are stored lower case, so "Empty@Example.com" is the same address.
    $id = (int) $pdo->query("SELECT user_id FROM user WHERE email = 'empty@example.com'")
        ->fetchColumn();
    return auth_delete_account($id, ' Empty@Example.com ')['ok'] === true;
})());

$result = auth_delete_account($alice, 'alice@example.com');
check('the deletion succeeds', $result['ok'] === true);
check('no error is reported',  $result['error'] === null);

$aliceAfter = owned_counts($pdo, $alice);
check('the user row is gone', $aliceAfter['user'] === 0);
check('every learner-scoped table is empty for them', (function () use ($aliceAfter) {
    foreach ($aliceAfter as $count) {
        if ($count !== 0) { return false; }
    }
    return true;
})());

// The tables that cascade through learning_resource rather than user directly.
check('their chunks cascaded away',
    (int) $pdo->query('SELECT COUNT(*) FROM resource_chunk')->fetchColumn() === 2);
check('their activities cascaded away',
    (int) $pdo->query('SELECT COUNT(*) FROM learning_activity')->fetchColumn() === 1);
check('their items cascaded away',
    (int) $pdo->query('SELECT COUNT(*) FROM activity_item')->fetchColumn() === 1);
check('their responses cascaded away',
    (int) $pdo->query('SELECT COUNT(*) FROM attempt_response')->fetchColumn() === 1);

section('And nothing belonging to anyone else');

check('every one of the other learner\'s rows survived',
    owned_counts($pdo, $bob) === $bobBefore);
check('their account still exists',
    (int) $pdo->query("SELECT COUNT(*) FROM user WHERE email = 'bob@example.com'")
        ->fetchColumn() === 1);
check('their password still works',
    password_verify('another good password',
        (string) $pdo->query("SELECT password_hash FROM user WHERE user_id = $bob")->fetchColumn()));
check('their chat history survived',
    (int) $pdo->query("SELECT COUNT(*) FROM ai_interaction WHERE user_id = $bob")
        ->fetchColumn() === 1);
check('their support request survived',
    (int) $pdo->query("SELECT COUNT(*) FROM support_request WHERE user_id = $bob")
        ->fetchColumn() === 1);

check('deleting an already deleted account is refused',
    auth_delete_account($alice, 'alice@example.com')['ok'] === false);

check('the other learner can still be deleted in turn',
    auth_delete_account($bob, 'bob@example.com')['ok'] === true);
check('and then every table is empty', (function () use ($pdo) {
    foreach (array_keys(OWNED_TABLES) as $table) {
        if ((int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn() !== 0) {
            return false;
        }
    }
    foreach (['resource_chunk','learning_activity','activity_item','attempt_response'] as $table) {
        if ((int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn() !== 0) {
            return false;
        }
    }
    return true;
})());

echo "\n======================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
