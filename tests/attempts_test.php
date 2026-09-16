<?php
/**
 * EduFlex — attempts, scoring and mastery test suite.
 *
 *     php tests/attempts_test.php
 *
 * The mastery arithmetic gets the most attention, because every screen in the
 * system reads the number it produces and the whole study rests on it being
 * defensible.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/attempts.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "  PASS  $label\n"; }
    else            { $failed++; echo "  FAIL  $label\n"; }
}
function near(float $a, float $b, float $tolerance = 0.05): bool
{
    return abs($a - $b) <= $tolerance;
}
function section(string $name): void { echo "\n$name\n"; }

echo "EduFlex attempts and mastery suite\n==================================\n";

/* ----------------------------------------------------------- test database */

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("
CREATE TABLE learning_resource (
  resource_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
  title TEXT NOT NULL, processing_status TEXT NOT NULL DEFAULT 'processed');
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

$pdo->exec("INSERT INTO learning_resource (user_id, title) VALUES (1, 'Signals Reviewer')");
$pdo->exec("INSERT INTO topic_progress (user_id, resource_id, topic_name) VALUES (1, 1, 'Fourier Series')");
$pdo->exec("INSERT INTO topic_progress (user_id, resource_id, topic_name) VALUES (1, 1, 'Nyquist Sampling')");

/** Create an activity with N items whose correct answer is "Correct N". */
$makeActivity = static function (int $topicId, string $bloom, int $items) use ($pdo): int {
    $pdo->prepare('INSERT INTO learning_activity
        (resource_id, topic_progress_id, activity_type, title, bloom_level, difficulty_level)
        VALUES (1, ?, ?, ?, ?, ?)')
        ->execute([$topicId, 'practice_set', "$bloom set", $bloom, 'x']);
    $activityId = (int) $pdo->lastInsertId();
    for ($i = 1; $i <= $items; $i++) {
        $pdo->prepare('INSERT INTO activity_item
            (activity_id, question_text, item_type, options_json, correct_answer, explanation)
            VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([
                $activityId,
                "Question $i of the $bloom set, long enough to be valid",
                'multiple_choice',
                json_encode(["Correct $i", "Wrong A$i", "Wrong B$i", "Wrong C$i"]),
                "Correct $i",
                "Because option Correct $i is what the passage states.",
            ]);
    }
    return $activityId;
};

/* -------------------------------------------------------- starting attempts */

section('Starting an attempt');

$activity = $makeActivity(1, 'Remember', 4);
$start = attempt_start($activity, 1);
check('an attempt starts',            $start['ok'] === true);
check('it is not a resume',           $start['resumed'] === false);
check('total_items is recorded',
    (int) $pdo->query('SELECT total_items FROM activity_attempt WHERE attempt_id = '
        . (int) $start['attempt_id'])->fetchColumn() === 4);

$again = attempt_start($activity, 1);
check('starting again resumes the open attempt', $again['resumed'] === true);
check('and returns the same attempt',            $again['attempt_id'] === $start['attempt_id']);
check('no second attempt row was created',
    (int) $pdo->query('SELECT COUNT(*) FROM activity_attempt')->fetchColumn() === 1);

check('another learner cannot start it',   attempt_start($activity, 2)['ok'] === false);
check('an unknown activity is rejected',   attempt_start(9999, 1)['ok'] === false);

$empty = $makeActivity(1, 'Remember', 0);
check('an activity with no questions is rejected', attempt_start($empty, 1)['ok'] === false);

/* ------------------------------------------------------------ loading */

section('Loading an attempt for the runner');

$attemptId = (int) $start['attempt_id'];
$load = attempt_load($attemptId, 1);
check('the attempt loads',              $load['ok'] === true);
check('every question is returned',     count($load['items']) === 4);
check('options come through',           count($load['items'][0]['options']) === 4);
check('nothing is answered yet',        $load['answered'] === 0);

check('THE CORRECT ANSWER IS NEVER SENT TO THE PAGE', (function () use ($load) {
    foreach ($load['items'] as $item) {
        if (array_key_exists('correct_answer', $item) || array_key_exists('explanation', $item)) {
            return false;
        }
    }
    return true;
})());

check('another learner cannot load it', attempt_load($attemptId, 2)['ok'] === false);

/* ------------------------------------------------------------- answering */

section('Answering');

$items = $load['items'];

$r = attempt_answer($attemptId, $items[0]['item_id'], 'Correct 1', 1);
check('a right answer is marked correct',  $r['ok'] === true && $r['correct'] === true);
check('the explanation comes back',        is_string($r['explanation']));
check('the progress count advances',       $r['answered'] === 1);

$r = attempt_answer($attemptId, $items[1]['item_id'], 'Wrong A2', 1);
check('a wrong answer is marked incorrect', $r['correct'] === false);
check('and the right answer is revealed',   $r['answer'] === 'Correct 2');

$r = attempt_answer($attemptId, $items[2]['item_id'], '  correct 3  ', 1);
check('matching ignores case and spacing',  $r['correct'] === true);

$r = attempt_answer($attemptId, $items[3]['item_id'], null, 1);
check('a skipped question is recorded',     $r['ok'] === true);
check('and counts as incorrect',            $r['correct'] === false);

$dup = attempt_answer($attemptId, $items[0]['item_id'], 'Correct 1', 1);
check('the same question cannot be answered twice', $dup['ok'] === false);
check('so mastery cannot be double-counted',
    (int) $pdo->query('SELECT COUNT(*) FROM attempt_response WHERE attempt_id = '
        . $attemptId)->fetchColumn() === 4);

$otherActivity = $makeActivity(2, 'Apply', 2);
$otherItem = (int) $pdo->query('SELECT item_id FROM activity_item WHERE activity_id = '
    . $otherActivity . ' LIMIT 1')->fetchColumn();
check('an item from another set is rejected',
    attempt_answer($attemptId, $otherItem, 'x', 1)['ok'] === false);
check('another learner cannot answer',
    attempt_answer($attemptId, $items[0]['item_id'], 'x', 2)['ok'] === false);

/* -------------------------------------------------------------- finishing */

section('Finishing and scoring');

$fin = attempt_finish($attemptId, 1);
check('the attempt finishes',        $fin['ok'] === true);
check('2 of 4 correct scores 50',    near((float) $fin['score'], 50.0));
check('the correct count is right',  $fin['correct'] === 2);
check('the score is stored',
    near((float) $pdo->query('SELECT score FROM activity_attempt WHERE attempt_id = '
        . $attemptId)->fetchColumn(), 50.0));
check('completed_at is set',
    $pdo->query('SELECT completed_at FROM activity_attempt WHERE attempt_id = '
        . $attemptId)->fetchColumn() !== null);
check('it cannot be finished twice', attempt_finish($attemptId, 1)['ok'] === false);
check('mastery was updated as part of finishing', is_array($fin['mastery']));

section('Unanswered questions count against the score');

$a2 = $makeActivity(1, 'Remember', 4);
$s2 = attempt_start($a2, 1);
$id2 = (int) $s2['attempt_id'];
$load2 = attempt_load($id2, 1);
attempt_answer($id2, $load2['items'][0]['item_id'], 'Correct 1', 1);
$f2 = attempt_finish($id2, 1);
check('1 right out of 4 questions scores 25, not 100', near((float) $f2['score'], 25.0));

$a3 = $makeActivity(1, 'Remember', 2);
$s3 = attempt_start($a3, 1);
check('finishing with nothing answered is refused',
    attempt_finish((int) $s3['attempt_id'], 1)['ok'] === false);

/* ---------------------------------------------------------------- mastery */

section('The mastery formula');

/** Wipe a topic and record answers newest-last, so index 0 is the OLDEST. */
$seed = static function (int $topicId, array $answers) use ($pdo, $makeActivity): void {
    $pdo->exec("DELETE FROM attempt_response WHERE attempt_id IN
        (SELECT aa.attempt_id FROM activity_attempt aa
          JOIN learning_activity la ON la.activity_id = aa.activity_id
         WHERE la.topic_progress_id = $topicId)");
    $pdo->exec("DELETE FROM activity_attempt WHERE activity_id IN
        (SELECT activity_id FROM learning_activity WHERE topic_progress_id = $topicId)");
    $pdo->exec("DELETE FROM activity_item WHERE activity_id IN
        (SELECT activity_id FROM learning_activity WHERE topic_progress_id = $topicId)");
    $pdo->exec("DELETE FROM learning_activity WHERE topic_progress_id = $topicId");

    $clock = 0;
    foreach ($answers as [$bloom, $correct]) {
        $clock++;
        $activityId = $makeActivity($topicId, $bloom, 1);
        $pdo->prepare('INSERT INTO activity_attempt
            (user_id, activity_id, score, total_items, completed_at)
            VALUES (1, ?, 0, 1, CURRENT_TIMESTAMP)')->execute([$activityId]);
        $attemptId = (int) $pdo->lastInsertId();
        $itemId = (int) $pdo->query('SELECT item_id FROM activity_item WHERE activity_id = '
            . $activityId)->fetchColumn();
        $pdo->prepare('INSERT INTO attempt_response
            (attempt_id, item_id, user_answer, is_correct, answered_at)
            VALUES (?, ?, ?, ?, datetime("now", "+" || ? || " seconds"))')
            ->execute([$attemptId, $itemId, 'x', $correct ? 1 : 0, (string) $clock]);
    }
};

$seed(1, [['Remember', true], ['Remember', true], ['Remember', true]]);
$m = mastery_recalculate(1, 1);
check('all correct gives 100',        near($m['mastery'], 100.0));
check('scored_items counts answers',  $m['scored_items'] === 3);

$seed(1, [['Remember', false], ['Remember', false], ['Remember', false]]);
$m = mastery_recalculate(1, 1);
check('all wrong gives 0',            near($m['mastery'], 0.0));

// Two Remember answers. Newest weight 1.0, next 1/1.15 = 0.8696.
// Recent correct  -> 1.0     / 1.8696 = 53.49
// Recent wrong    -> 0.8696  / 1.8696 = 46.51
$seed(1, [['Remember', false], ['Remember', true]]);   // last seeded is newest
$recentRight = mastery_recalculate(1, 1)['mastery'];
$seed(1, [['Remember', true], ['Remember', false]]);
$recentWrong = mastery_recalculate(1, 1)['mastery'];

check('a recent correct answer outweighs an old wrong one', $recentRight > $recentWrong);
check('recent-correct works out at 53.49', near($recentRight, 53.49, 0.1));
check('recent-wrong works out at 46.51',   near($recentWrong, 46.51, 0.1));

// Bloom weighting. Create is 2.2, Remember is 1.0.
// Right at Create (newest, w 2.2), wrong at Remember (w 0.8696) -> 2.2/3.0696 = 71.67
// Right at Remember (newest, w 1.0), wrong at Create (w 1.913)  -> 1.0/2.913  = 34.33
$seed(1, [['Remember', false], ['Create', true]]);
$hardRight = mastery_recalculate(1, 1)['mastery'];
$seed(1, [['Create', false], ['Remember', true]]);
$easyRight = mastery_recalculate(1, 1)['mastery'];

check('getting a harder question right is worth more', $hardRight > $easyRight);
check('right-at-Create works out at 71.67',  near($hardRight, 71.67, 0.15));
check('right-at-Remember works out at 34.33', near($easyRight, 34.33, 0.15));

section('Bands and idempotence');

$seed(1, array_fill(0, 6, ['Remember', true]));
$m = mastery_recalculate(1, 1);
check('6 correct answers reads as mastered', $m['band'] === 'mastered');
check('the band is stored on the topic',
    $pdo->query('SELECT weakness_priority FROM topic_progress WHERE topic_progress_id = 1')
        ->fetchColumn() === 'mastered');

$first  = mastery_recalculate(1, 1)['mastery'];
$second = mastery_recalculate(1, 1)['mastery'];
check('recalculating gives the same answer', near($first, $second, 0.001));

$seed(1, [['Remember', true], ['Remember', true], ['Remember', true]]);
$m = mastery_recalculate(1, 1);
check('under 5 answers the band is "none" however high the score',
    $m['band'] === 'none' && near($m['mastery'], 100.0));

check('the delta against the previous value is reported', (function () use ($seed) {
    $seed(1, array_fill(0, 5, ['Remember', false]));
    mastery_recalculate(1, 1);
    $seed(1, array_fill(0, 5, ['Remember', true]));
    return mastery_recalculate(1, 1)['delta'] > 90;
})());

check('a topic with no answers reads zero', (function () use ($seed) {
    $seed(2, []);
    $m = mastery_recalculate(2, 1);
    return near($m['mastery'], 0.0) && $m['scored_items'] === 0 && $m['band'] === 'none';
})());

/* --------------------------------------------------------- recommendation */

section('Recommendation');

$pdo->exec('DELETE FROM recommendation');
$seed(1, array_fill(0, 6, ['Remember', false]));   // weak
$seed(2, array_fill(0, 6, ['Remember', true]));    // mastered
mastery_recalculate(1, 1);
mastery_recalculate(2, 1);

$rec = recommendation_refresh(1);
check('a recommendation is produced',        $rec['ok'] === true);
check('it picks the weakest topic',          $rec['topic'] === 'Fourier Series');
check('the reason states the mastery value',
    is_string($rec['reason']) && str_contains($rec['reason'], '%'));
check('the reason names the band',
    is_string($rec['reason']) && str_contains($rec['reason'], 'weak'));

recommendation_refresh(1);
check('only one recommendation stays open',
    (int) $pdo->query("SELECT COUNT(*) FROM recommendation WHERE status = 'new'")
        ->fetchColumn() === 1);
check('the previous one is superseded, not deleted',
    (int) $pdo->query("SELECT COUNT(*) FROM recommendation WHERE status = 'superseded'")
        ->fetchColumn() === 1);

check('nothing is recommended below the item minimum', (function () use ($pdo, $seed) {
    $pdo->exec('DELETE FROM recommendation');
    $seed(1, [['Remember', false], ['Remember', false]]);
    $seed(2, [['Remember', false]]);
    mastery_recalculate(1, 1);
    mastery_recalculate(2, 1);
    return recommendation_refresh(1)['ok'] === false;
})());

check('an all-mastered learner is left alone', (function () use ($pdo, $seed) {
    $pdo->exec('DELETE FROM recommendation');
    $seed(1, array_fill(0, 6, ['Remember', true]));
    $seed(2, array_fill(0, 6, ['Remember', true]));
    mastery_recalculate(1, 1);
    mastery_recalculate(2, 1);
    return recommendation_refresh(1)['ok'] === false
        && (int) $pdo->query("SELECT COUNT(*) FROM recommendation WHERE status = 'new'")
              ->fetchColumn() === 0;
})());

/* -------------------------------------------------------------- reviewing */

section('Reviewing a finished attempt');

// Build a fresh attempt here rather than reusing the one from the scoring
// section. The mastery tests above reseed topic 1, which deletes its
// activities, and a review of a deleted attempt proves nothing.
$reviewActivity = $makeActivity(1, 'Remember', 4);
$reviewStart    = attempt_start($reviewActivity, 1);
$reviewAttempt  = (int) $reviewStart['attempt_id'];
$reviewItems    = $pdo->query('SELECT item_id FROM activity_item WHERE activity_id = '
    . $reviewActivity . ' ORDER BY item_id ASC')->fetchAll(PDO::FETCH_COLUMN);

attempt_answer($reviewAttempt, (int) $reviewItems[0], 'Correct 1', 1);
attempt_answer($reviewAttempt, (int) $reviewItems[1], 'Wrong A2',  1);
attempt_answer($reviewAttempt, (int) $reviewItems[2], 'Wrong B3',  1);
// The fourth is left alone, so the review has a skipped question to report.
attempt_finish($reviewAttempt, 1);

$attemptId = $reviewAttempt;
$review    = attempt_review($attemptId, 1);
check('every question is returned',        count($review) === 4);
check('the correct answer is included now', $review[0]['correct_answer'] === 'Correct 1');
check('the learner answer is included',     $review[0]['user_answer'] === 'Correct 1');
check('correctness is flagged',             $review[0]['is_correct'] === true);
check('a skipped question shows as unanswered',
    $review[3]['answered'] === false && $review[3]['user_answer'] === null);
check('another learner sees nothing',       attempt_review($attemptId, 2) === []);

/* ------------------------------------------------- acting on the advice */

section('Practising what EduFlex recommends');

require_once __DIR__ . '/../includes/ai.php';
ai_provider(new MockProvider());

// A chunk to generate from, for the case where no stored set is free.
$pdo->exec("INSERT INTO resource_chunk (resource_id, chunk_index, content, word_count)
            VALUES (1, 0, 'Sampling requires twice the highest frequency present. "
          . "Aliasing follows when that rate is not met in practice.', 16)");

$pdo->exec('DELETE FROM recommendation');
$pdo->exec('DELETE FROM attempt_response');
$pdo->exec('DELETE FROM activity_attempt');
$pdo->exec('DELETE FROM activity_item');
$pdo->exec('DELETE FROM learning_activity');
$pdo->exec('UPDATE topic_progress SET mastery_score = 0, scored_items = 0');

check('with no topics at all there is nothing to practise',
    practice_next_topic(99)['ok'] === false);

// Topic 2 is the weaker of the two, both above the item minimum.
$seed(1, array_fill(0, 6, ['Remember', true]));
$seed(2, array_fill(0, 6, ['Remember', false]));
mastery_recalculate(1, 1);
mastery_recalculate(2, 1);

$next = practice_next_topic(1);
check('without a recommendation it falls back to the weakest topic',
    $next['ok'] === true && $next['topic_progress_id'] === 2);
check('and says that is what it did', $next['source'] === 'weakest');
check('and gives a reason naming the figure',
    str_contains((string) $next['reason'], '%'));

recommendation_refresh(1);
$next = practice_next_topic(1);
check('an open recommendation is preferred over the fallback',
    $next['source'] === 'recommendation');
check('and it names the topic', $next['topic_name'] === 'Nyquist Sampling');

check('a learner under the item minimum is still given somewhere to start',
    (function () use ($pdo) {
        $pdo->exec('DELETE FROM recommendation');
        $pdo->exec('UPDATE topic_progress SET mastery_score = 0, scored_items = 0');
        $r = practice_next_topic(1);
        return $r['ok'] === true && $r['source'] === 'default'
            && str_contains((string) $r['reason'], 'not have enough answers');
    })());

// An unattempted stored set must be reused rather than paying for a new one.
$pdo->exec('DELETE FROM attempt_response');
$pdo->exec('DELETE FROM activity_attempt');
$pdo->exec('DELETE FROM activity_item');
$pdo->exec('DELETE FROM learning_activity');
$spare = $makeActivity(1, 'Remember', 3);

$open = practice_start_topic(1, 1);
check('a practice attempt opens',        $open['ok'] === true);
check('the stored set was reused',       $open['generated'] === false);
check('and it is the one that was free', $open['activity_id'] === $spare);
check('the attempt is real',
    (int) $pdo->query('SELECT COUNT(*) FROM activity_attempt WHERE attempt_id = '
        . (int) $open['attempt_id'])->fetchColumn() === 1);

$again = practice_start_topic(1, 1);
check('pressing again resumes rather than opening a second attempt',
    $again['attempt_id'] === $open['attempt_id']);

check('a set is written only when no free one is left', (function () use ($pdo, $open) {
    // Finish the only stored set so nothing is left unattempted.
    $pdo->prepare('UPDATE activity_attempt SET completed_at = CURRENT_TIMESTAMP,
                   score = 0 WHERE attempt_id = ?')->execute([$open['attempt_id']]);
    $fresh = practice_start_topic(1, 1);
    return $fresh['ok'] === true && $fresh['generated'] === true;
})());

check('acting on a recommendation retires it', (function () use ($pdo) {
    $pdo->exec('DELETE FROM recommendation');
    $pdo->exec("INSERT INTO recommendation
        (user_id, topic_progress_id, recommended_level, recommended_activity,
         reason, status)
        VALUES (1, 1, 'Remember', 'Practice set', 'because', 'new')");
    practice_start_topic(1, 1);
    return (int) $pdo->query("SELECT COUNT(*) FROM recommendation
                               WHERE status = 'accepted'")->fetchColumn() === 1;
})());

check('a topic belonging to another learner cannot be started',
    practice_start_topic(1, 2)['ok'] === false);
check('an unknown topic cannot be started',
    practice_start_topic(9999, 1)['ok'] === false);

echo "\n==================================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
