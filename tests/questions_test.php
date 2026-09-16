<?php
/**
 * EduFlex — question generation test suite.
 *
 *     php tests/questions_test.php
 *
 * Runs against the mock provider and an in-memory database. No API key, no
 * network, no quota spent.
 *
 * The validator gets the most attention here on purpose. A question whose
 * stated answer is not among its own options would mark a learner wrong for
 * being right, and that corrupts their mastery score permanently.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/questions.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "  PASS  $label\n"; }
    else            { $failed++; echo "  FAIL  $label\n"; }
}
function section(string $name): void { echo "\n$name\n"; }

echo "EduFlex question generation suite\n=================================\n";

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
CREATE TABLE ai_interaction (
  interaction_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
  resource_id INTEGER NULL, prompt TEXT NOT NULL, response TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
");
db_set_connection($pdo);

$pdo->exec("INSERT INTO learning_resource (user_id, title) VALUES (1, 'Signals Reviewer')");
$chunks = [
    'A Fourier series decomposes a periodic signal into a sum of sinusoids. The harmonic content determines the shape.',
    'Nyquist sampling requires a rate of at least twice the highest frequency. Below that, aliasing corrupts the signal.',
    'A square wave contains only odd harmonics. Their amplitude falls off as one over n. Fourier analysis shows this clearly.',
];
foreach ($chunks as $i => $text) {
    $stmt = $pdo->prepare('INSERT INTO resource_chunk (resource_id, chunk_index, content) VALUES (1,?,?)');
    $stmt->execute([$i, $text]);
}
$pdo->exec("INSERT INTO topic_progress (user_id, resource_id, topic_name) VALUES (1, 1, 'Fourier Series')");
$pdo->exec("INSERT INTO topic_progress (user_id, resource_id, topic_name) VALUES (1, 1, 'Nyquist Sampling')");

/* --------------------------------------------------------- Bloom stepping */

section('Choosing the Bloom level');

check('a topic with no attempts starts at Remember',
    questions_next_bloom_level(1, 1) === 'Remember');

// Helper: record a completed attempt at a given level and score.
$clock = 0;
$attempt = static function (int $topicId, string $bloom, float $score) use ($pdo, &$clock): void {
    $clock++;
    $pdo->prepare('INSERT INTO learning_activity
        (resource_id, topic_progress_id, activity_type, title, bloom_level, difficulty_level)
        VALUES (1, ?, ?, ?, ?, ?)')
        ->execute([$topicId, 'practice_set', 'set', $bloom, 'x']);
    $activityId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO activity_attempt
        (user_id, activity_id, score, total_items, completed_at)
        VALUES (1, ?, ?, 8, datetime("now", "+" || ? || " seconds"))')
        ->execute([$activityId, $score, (string) $clock]);
};

$attempt(1, 'Remember', 92.0);
check('a score of 92 steps up from Remember to Understand',
    questions_next_bloom_level(1, 1) === 'Understand');

$attempt(1, 'Understand', 70.0);
check('a score of 70 holds the level',
    questions_next_bloom_level(1, 1) === 'Understand');

$attempt(1, 'Understand', 41.0);
check('a score of 41 steps back down to Remember',
    questions_next_bloom_level(1, 1) === 'Remember');

$attempt(1, 'Remember', 30.0);
check('it cannot step below Remember',
    questions_next_bloom_level(1, 1) === 'Remember');

$attempt(1, 'Create', 99.0);
check('it cannot step above Create',
    questions_next_bloom_level(1, 1) === 'Create');

check('85 exactly steps up, matching the mastery threshold', (function () use ($attempt) {
    $attempt(2, 'Apply', 85.0);
    return questions_next_bloom_level(2, 1) === 'Analyze';
})());

check('another learner sees their own progression, not this one',
    questions_next_bloom_level(1, 99) === 'Remember');

/* --------------------------------------------------------------- retrieval */

section('Choosing which passages to send');

$ctx = questions_select_context(1, 'Fourier Series');
check('returns passages',                 count($ctx) > 0);
check('never sends more than the cap',    count($ctx) <= QUESTION_CONTEXT_CHUNKS);
check('the best match leads',
    str_contains(mb_strtolower($ctx[0]['content']), 'fourier'));

$nyq = questions_select_context(1, 'Nyquist Sampling', 1);
check('a different topic selects a different passage',
    str_contains(mb_strtolower($nyq[0]['content']), 'nyquist'));

$odd = questions_select_context(1, 'Zzzz Unrelated', 2);
check('an unmatched topic still returns something to work with', count($odd) === 2);
check('the fallback keeps document order',
    $odd[0]['chunk_index'] < $odd[1]['chunk_index']);

check('a resource with no chunks returns nothing',
    questions_select_context(999, 'Anything') === []);

/* -------------------------------------------------------------- validation */

section('Validation: the answer must be selectable');

$good = ['questions' => [[
    'question'    => 'Which harmonics does a square wave contain?',
    'options'     => ['Only odd harmonics', 'Only even harmonics', 'All harmonics equally', 'No harmonics'],
    'answer'      => 'Only odd harmonics',
    'explanation' => 'A square wave contains only odd harmonics.',
]]];
$v = questions_validate($good);
check('a well-formed question is accepted',  count($v['items']) === 1);
check('nothing is rejected',                 $v['rejected'] === 0);
check('the answer is kept',                  $v['items'][0]['answer'] === 'Only odd harmonics');
check('all four options are kept',           count($v['items'][0]['options']) === 4);

$mismatch = $good;
$mismatch['questions'][0]['answer'] = 'Only prime harmonics';
$v = questions_validate($mismatch);
check('an answer absent from the options is REJECTED', $v['items'] === []);
check('and the reason is recorded',
    isset($v['reasons']['answer is not one of the options']));

$caseOnly = $good;
$caseOnly['questions'][0]['answer'] = 'only ODD harmonics';
$v = questions_validate($caseOnly);
check('a case-only difference still matches', count($v['items']) === 1);
check('and the option casing is what gets stored',
    $v['items'][0]['answer'] === 'Only odd harmonics');

$lettered = $good;
$lettered['questions'][0]['options'] = ['A) Only odd harmonics', 'B) Only even harmonics',
                                        'C) All harmonics equally', 'D) No harmonics'];
$lettered['questions'][0]['answer']  = 'A) Only odd harmonics';
$v = questions_validate($lettered);
check('letter prefixes are stripped from options', count($v['items']) === 1);
check('and from the answer, so they still match',
    $v['items'][0]['answer'] === 'Only odd harmonics');

section('Validation: option integrity');

$threeOptions = $good;
array_pop($threeOptions['questions'][0]['options']);
check('three options is rejected', questions_validate($threeOptions)['items'] === []);

$fiveOptions = $good;
$fiveOptions['questions'][0]['options'][] = 'A fifth option';
check('five options is rejected', questions_validate($fiveOptions)['items'] === []);

$dupOptions = $good;
$dupOptions['questions'][0]['options'][1] = 'Only odd harmonics';
$v = questions_validate($dupOptions);
check('duplicate options are rejected', $v['items'] === []);
check('and the reason is recorded', isset($v['reasons']['duplicate options']));

$allAbove = $good;
$allAbove['questions'][0]['options'][3] = 'All of the above';
check('"all of the above" is rejected', questions_validate($allAbove)['items'] === []);

$noneAbove = $good;
$noneAbove['questions'][0]['options'][3] = 'none of the above';
check('"none of the above" is rejected, whatever the casing',
    questions_validate($noneAbove)['items'] === []);

section('Validation: question text');

$stub = $good;
$stub['questions'][0]['question'] = 'Why?';
check('a trivially short question is rejected', questions_validate($stub)['items'] === []);

$noText = $good;
unset($noText['questions'][0]['question']);
check('a missing question is rejected', questions_validate($noText)['items'] === []);

$twice = ['questions' => [$good['questions'][0], $good['questions'][0]]];
$v = questions_validate($twice);
check('the same question twice keeps only one', count($v['items']) === 1);
check('and records it as a duplicate', isset($v['reasons']['duplicate question']));

section('Validation: malformed replies');

check('null is handled',              questions_validate(null)['items'] === []);
check('the wrong shape is handled',   questions_validate(['questions' => 'nope'])['items'] === []);
check('an empty set is handled',      questions_validate(['questions' => []])['items'] === []);
check('a bare array without the key works',
    count(questions_validate([$good['questions'][0]])['items']) === 1);
check('a valid question survives beside a broken one', (function () use ($good) {
    $mixed = ['questions' => [
        ['question' => 'Broken, no options at all here', 'answer' => 'x'],
        $good['questions'][0],
    ]];
    $v = questions_validate($mixed);
    return count($v['items']) === 1 && $v['rejected'] === 1;
})());

/* ------------------------------------------------------- generation, whole */

section('Generating and storing a set');

ai_provider(new MockProvider());
$gen = questions_generate(2, 1, 'Apply');

check('generation succeeds',            $gen['ok'] === true);
check('an activity id comes back',      is_int($gen['activity_id']) && $gen['activity_id'] > 0);
check('the full set is stored',         $gen['items'] === QUESTIONS_PER_SET);
check('the requested Bloom level is used', $gen['bloom'] === 'Apply');
check('nothing was discarded',          $gen['rejected'] === 0);

$act = $pdo->query('SELECT * FROM learning_activity WHERE activity_id = ' . (int) $gen['activity_id'])->fetch();
check('the activity is tagged with its topic', (int) $act['topic_progress_id'] === 2);
check('difficulty is derived from the level', $act['difficulty_level'] === 'intermediate');
check('the title names the topic',      str_contains((string) $act['title'], 'Nyquist Sampling'));

$items = $pdo->query('SELECT * FROM activity_item WHERE activity_id = ' . (int) $gen['activity_id'])->fetchAll();
check('every item was written',         count($items) === QUESTIONS_PER_SET);
check('items are multiple choice',      $items[0]['item_type'] === 'multiple_choice');

$decoded = json_decode((string) $items[0]['options_json'], true);
check('options are stored as JSON',     is_array($decoded) && count($decoded) === 4);
check('the stored answer is one of the stored options',
    in_array($items[0]['correct_answer'], $decoded, true));

check('EVERY stored item has a selectable answer', (function () use ($items) {
    foreach ($items as $item) {
        $options = json_decode((string) $item['options_json'], true);
        if (!is_array($options) || !in_array($item['correct_answer'], $options, true)) {
            return false;
        }
    }
    return true;
})());

check('the call was logged',
    (int) $pdo->query('SELECT COUNT(*) FROM ai_interaction')->fetchColumn() > 0);

check('the level is chosen automatically when none is given', (function () {
    $g = questions_generate(1, 1);
    return $g['ok'] === true && $g['bloom'] === 'Create';
})());

/* ------------------------------------------------------------ failure paths */

section('When generation cannot succeed');

$dead = new MockProvider();
$dead->queue(AiResult::failure('The provider rate limit was reached.'));
ai_provider($dead);
$bad = questions_generate(2, 1, 'Apply');
check('a provider failure is reported',  $bad['ok'] === false);
check('the reason is passed through',
    is_string($bad['error']) && str_contains($bad['error'], 'rate limit'));
check('no activity row is left behind',
    (int) $pdo->query("SELECT COUNT(*) FROM learning_activity WHERE title LIKE '%practice set%'")->fetchColumn() === 2);

$thin = new MockProvider();
$thin->queue(new AiResult(true, '{}', ['questions' => [
    ['question' => 'Only one usable question in this whole set',
     'options' => ['a', 'b', 'c', 'd'], 'answer' => 'a', 'explanation' => ''],
]], null, 5, 1, 'mock'));
ai_provider($thin);
$few = questions_generate(2, 1, 'Apply');
check('too few usable questions is a failure', $few['ok'] === false);
check('and it says how many survived',
    is_string($few['error']) && str_contains($few['error'], 'usable'));

section('Guard clauses');

ai_provider(new MockProvider());
check('an unknown topic is rejected',       questions_generate(9999, 1)['ok'] === false);
check('another learner cannot generate',    questions_generate(1, 2)['ok'] === false);

$pdo->exec("INSERT INTO learning_resource (user_id, title, processing_status)
            VALUES (1, 'Unread', 'pending')");
$pdo->exec("INSERT INTO topic_progress (user_id, resource_id, topic_name)
            VALUES (1, 2, 'Orphan Topic')");
$r = questions_generate(3, 1);
check('an unread resource is rejected',     $r['ok'] === false);
check('and it says why',
    is_string($r['error']) && str_contains($r['error'], 'not been read'));

/* -------------------------------------------------------------- difficulty */

section('Difficulty labels');

check('Remember is foundational',   questions_difficulty_for_bloom('Remember') === 'foundational');
check('Apply is intermediate',      questions_difficulty_for_bloom('Apply') === 'intermediate');
check('Evaluate is advanced',       questions_difficulty_for_bloom('Evaluate') === 'advanced');
check('Create is advanced',         questions_difficulty_for_bloom('Create') === 'advanced');

/* ------------------------------------------------------------------ lists */

section('Listing');

$list = questions_activity_list(1);
check('activities are listed',              count($list) > 0);
check('item counts are included',           (int) $list[0]['item_count'] > 0);
check('another learner sees none',          questions_activity_list(2) === []);

$topics = questions_generatable_topics(1);
check('generatable topics are listed',      count($topics) >= 2);
check('an unread resource is excluded',
    !in_array('Orphan Topic', array_column($topics, 'topic_name'), true));

echo "\n=================================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
