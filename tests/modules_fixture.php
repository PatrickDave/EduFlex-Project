<?php
/**
 * EduFlex — fixture helper for tests/modules_browser_test.py.
 *
 *     php tests/modules_fixture.php seed    <email>
 *     php tests/modules_fixture.php cleanup <email>
 *
 * The browser suite does everything it can through the real interface: it
 * registers its own account and uploads its own file. Two things it cannot do
 * without spending provider quota are detecting topics and holding a
 * conversation, so those rows are written here instead. Nothing else is faked.
 *
 * EVERY command refuses an address that is not one of this suite's own. That
 * guard is not decoration. On 25 October 2026 an ad-hoc version of this audit
 * seeded its resources pointing at Patrick's real manuscript file, and
 * auth_delete_account() removes uploaded files from disk: deleting the throwaway
 * accounts would have taken his 16 MB document with them. It was caught before
 * the delete ran. See CLAUDE.md rule 16.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This script runs from the command line only.\n");
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/resources.php';
// attempt_start(), attempt_answer() and attempt_finish() live here. The seed
// drives the real scoring path rather than writing scores by hand.
require_once __DIR__ . '/../includes/attempts.php';

/** Every account this suite creates starts with this. Nothing else is touchable. */
const FIXTURE_PREFIX = 'modaudit_';

$command = $argv[1] ?? '';
$email   = $argv[2] ?? '';

if ($command === '' || $email === '') {
    exit("usage: php tests/modules_fixture.php seed|cleanup <email>\n");
}

/* The guard. A typo here would otherwise delete a real learner and their
   uploads, which is exactly the accident this suite must never cause. */
if (!str_starts_with($email, FIXTURE_PREFIX)) {
    fwrite(STDERR, sprintf(
        "REFUSED: '%s' is not a fixture account.\n"
        . "This script only touches addresses beginning '%s'.\n",
        $email, FIXTURE_PREFIX
    ));
    exit(2);
}

$stmt = db()->prepare('SELECT user_id FROM user WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$userId = (int) $stmt->fetchColumn();

if ($userId === 0) {
    fwrite(STDERR, "No such fixture account: $email\n");
    exit(3);
}

if ($command === 'seed') {
    // The resource was uploaded through the real form by the browser suite.
    $stmt = db()->prepare(
        'SELECT resource_id FROM learning_resource
          WHERE user_id = ? ORDER BY resource_id DESC LIMIT 1'
    );
    $stmt->execute([$userId]);
    $resourceId = (int) $stmt->fetchColumn();

    if ($resourceId === 0) {
        fwrite(STDERR, "That account has no uploaded material yet.\n");
        exit(4);
    }

    /* Three topics, because exam_is_available() needs EXAM_MIN_TOPICS (2) before
       the mock examination entry point appears. Detecting them for real would
       cost 12 provider calls per run. */
    $insert = db()->prepare(
        'INSERT INTO topic_progress (user_id, resource_id, topic_name, mastery_score, scored_items)
         VALUES (?, ?, ?, 0, 0)'
    );
    foreach (['Set Theory Basics', 'Venn Diagrams', 'Subset Notation'] as $topic) {
        $insert->execute([$userId, $resourceId, $topic]);
    }

    /* One conversation turn, so the Clear chat control appears. chat_thread()
       selects on prompt LIKE 'chat:%', so the prefix is load-bearing. */
    db()->prepare('INSERT INTO ai_interaction (user_id, resource_id, prompt, response) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $resourceId, 'chat:What is a subset?',
                   'A set whose every member also belongs to another set.']);

    /* A completed practice attempt.
     *
     * Only the question text is invented here, because writing it is what costs
     * a provider call. Everything after that runs through the real functions:
     * attempt_answer() marks each response and attempt_finish() scores the
     * attempt, recalculates mastery and refreshes the recommendation.
     *
     * Without this the dashboard sits in its empty state, because
     * stats_empty_state() suppresses the recommendation until there are scored
     * answers. That is correct behaviour, and it means a fixture with topics but
     * no answers cannot exercise sub-modules 4.4, 4.5, 5.1, 5.2 or 5.4 at all.
     */
    $topicId = (int) db()->query(
        'SELECT topic_progress_id FROM topic_progress
          WHERE user_id = ' . $userId . ' ORDER BY topic_progress_id LIMIT 1'
    )->fetchColumn();

    db()->prepare(
        'INSERT INTO learning_activity
            (resource_id, topic_progress_id, activity_type, title, bloom_level, difficulty_level)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$resourceId, $topicId, 'practice_set',
                'Audit practice set', 'Remember', 'easy']);
    $activityId = (int) db()->lastInsertId();

    $item = db()->prepare(
        'INSERT INTO activity_item
            (activity_id, question_text, item_type, options_json, correct_answer, explanation)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $options = json_encode(['A set', 'A number', 'A graph', 'A matrix']);
    for ($i = 1; $i <= 8; $i++) {
        $item->execute([$activityId, "Audit question $i: what is a collection of distinct objects?",
                        'multiple_choice', $options, 'A set', 'By definition.']);
    }

    $start = attempt_start($activityId, $userId);
    if (empty($start['ok'])) {
        fwrite(STDERR, 'could not start the attempt: ' . (string) ($start['error'] ?? '?') . "\n");
        exit(6);
    }
    $attemptId = (int) $start['attempt_id'];

    /* Six right out of eight, so the score is neither 0 nor 100 and the band is
       a real one rather than an edge case. */
    $items = db()->query(
        'SELECT item_id FROM activity_item WHERE activity_id = ' . $activityId . ' ORDER BY item_id'
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($items as $index => $itemId) {
        attempt_answer($attemptId, (int) $itemId, $index < 6 ? 'A set' : 'A graph', $userId);
    }
    $finish = attempt_finish($attemptId, $userId);

    printf(
        "seeded user %d resource %d: 3 topics, 1 conversation turn, "
        . "1 completed attempt (score %s of 8)\n",
        $userId, $resourceId, (string) ($finish['score'] ?? '?')
    );
    exit(0);
}

if ($command === 'cleanup') {
    /* auth_delete_account() is the learner's own withdrawal mechanism, so this
       exercises it rather than deleting rows by hand. It cascades through every
       table and removes the account's uploaded files. */
    $result = auth_delete_account($userId, $email);

    if (!$result['ok']) {
        fwrite(STDERR, 'cleanup failed: ' . (string) $result['error'] . "\n");
        exit(5);
    }

    printf("deleted user %d (%d file%s removed)\n",
        $userId, $result['files'], $result['files'] === 1 ? '' : 's');
    exit(0);
}

fwrite(STDERR, "Unknown command: $command\n");
exit(1);
