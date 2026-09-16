<?php
/**
 * EduFlex — mock examination test suite.
 *
 *     php tests/exams_test.php
 *
 * Runs against the mock provider and an in-memory database. No API key, no
 * network, no quota spent.
 *
 * Four things matter here, and the last one is why the schema changed:
 *
 *   An examination spans several topics. One topic is a practice set with a
 *   longer name, so a learner without enough topics is told to practise rather
 *   than handed something misnamed.
 *
 *   It reuses before it generates. Generation is the only part of EduFlex that
 *   costs money, and a 20-question examination written from scratch every time
 *   would be three provider calls. The suite asserts that an examination built
 *   from stored questions makes none.
 *
 *   It never reuses a question the learner has already answered. That would
 *   test recall of the answer instead of the material, and would put one item
 *   into mastery twice.
 *
 *   Exam answers move mastery, for every topic the examination touched. Before
 *   activity_item carried its own topic, an item's topic came from its
 *   activity, and an examination belongs to no single topic. A learner could
 *   have answered twenty questions and seen nothing move.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/exams.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "  PASS  $label\n"; }
    else            { $failed++; echo "  FAIL  $label\n"; }
}
function section(string $name): void { echo "\n$name\n"; }

echo "EduFlex mock examination suite\n==============================\n";

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
CREATE TABLE notification (
  notification_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
  notification_type TEXT NOT NULL, title TEXT NOT NULL, message TEXT NOT NULL,
  is_read INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE ai_interaction (
  interaction_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
  resource_id INTEGER NULL, prompt TEXT NOT NULL, response TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
");
db_set_connection($pdo);

$pdo->exec("INSERT INTO learning_resource (user_id, title) VALUES (1, 'Signals Reviewer')");
foreach ([
    'A Fourier series decomposes a periodic signal into a sum of sinusoids, and the harmonic content determines the shape of the waveform.',
    'Nyquist sampling requires a rate of at least twice the highest frequency present, or aliasing corrupts the reconstruction.',
    'Convolution in the time domain corresponds to multiplication in the frequency domain, which is what makes filter design tractable.',
] as $i => $text) {
    $pdo->prepare('INSERT INTO resource_chunk (resource_id, chunk_index, content) VALUES (1,?,?)')
        ->execute([$i, $text]);
}

/** Add a topic and return its id. */
$addTopic = static function (string $name, float $mastery = 0.0) use ($pdo): int {
    $pdo->prepare('INSERT INTO topic_progress (user_id, resource_id, topic_name, mastery_score)
                   VALUES (1, 1, ?, ?)')->execute([$name, $mastery]);
    return (int) $pdo->lastInsertId();
};

/** Give a topic a stored practice set of $n questions. */
$stockSet = static function (int $topicId, string $bloom, int $n) use ($pdo): int {
    $pdo->prepare("INSERT INTO learning_activity
        (resource_id, topic_progress_id, activity_type, title, bloom_level, difficulty_level)
        VALUES (1, ?, 'practice_set', ?, ?, 'easy')")
        ->execute([$topicId, $bloom . ' set', $bloom]);
    $activityId = (int) $pdo->lastInsertId();
    for ($i = 1; $i <= $n; $i++) {
        $pdo->prepare('INSERT INTO activity_item
            (activity_id, question_text, item_type, options_json, correct_answer)
            VALUES (?, ?, ?, ?, ?)')
            ->execute([$activityId, "Stored question $i for topic $topicId ($bloom)",
                       'multiple_choice',
                       json_encode(["Right $i", "Wrong A$i", "Wrong B$i", "Wrong C$i"]),
                       "Right $i"]);
    }
    return $activityId;
};

/* ------------------------------------------------------------- arithmetic */

section('Sharing questions across topics');

check('20 over 3 topics is 7, 7, 6',   exam_share_questions(20, 3) === [7, 7, 6]);
check('nothing is lost to rounding',   array_sum(exam_share_questions(20, 3)) === 20);
check('20 over 2 topics is 10, 10',    exam_share_questions(20, 2) === [10, 10]);
check('the remainder goes to the weakest topics first',
    exam_share_questions(10, 3) === [4, 3, 3]);
check('no topics yields nothing',      exam_share_questions(20, 0) === []);
check('no questions yields nothing',   exam_share_questions(0, 3) === []);

check('every split sums to the total, across many shapes', (function () {
    for ($total = 1; $total <= 40; $total++) {
        for ($topics = 1; $topics <= 6; $topics++) {
            if (array_sum(exam_share_questions($total, $topics)) !== $total) { return false; }
        }
    }
    return true;
})());

/* ------------------------------------------------------ too few topics */

section('An examination needs several topics');

check('no topics at all is refused',   exam_start(1)['ok'] === false);
check('and it is not offered',         exam_is_available(1) === false);

$fourier = $addTopic('Fourier Series', 30.0);

check('one topic is still refused',    exam_start(1)['ok'] === false);
check('still not offered',             exam_is_available(1) === false);
check('the refusal explains what to do', (function () {
    $r = exam_start(1);
    return str_contains((string) $r['error'], 'only one so far');
})());

$nyquist     = $addTopic('Nyquist Sampling', 55.0);
$convolution = $addTopic('Convolution', 80.0);

check('three topics makes it available', exam_is_available(1) === true);

/* ------------------------------------------------------- topic selection */

section('Which topics it covers');

$selected = exam_select_topics(1);
check('it takes at most three',   count($selected) <= EXAM_MAX_TOPICS);
check('weakest first',            (int) $selected[0]['topic_progress_id'] === $fourier);
check('then the next weakest',    (int) $selected[1]['topic_progress_id'] === $nyquist);

check('a topic on unprocessed material is excluded', (function () use ($pdo) {
    $pdo->exec("INSERT INTO learning_resource (user_id, title, processing_status)
                VALUES (1, 'Still processing', 'pending')");
    $rid = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO topic_progress (user_id, resource_id, topic_name, mastery_score)
                   VALUES (1, ?, ?, 0)')->execute([$rid, 'Not ready yet']);
    foreach (exam_select_topics(1, 10) as $t) {
        if ($t['topic_name'] === 'Not ready yet') { return false; }
    }
    return true;
})());

check('another learner sees none of these topics', exam_select_topics(2) === []);

/* --------------------------------------------- assembling from stored sets */

section('Assembling from questions that already exist');

// Enough stored questions that nothing needs generating.
$stockSet($fourier, 'Remember', 8);
$stockSet($nyquist, 'Understand', 8);
$stockSet($convolution, 'Apply', 8);

$callsBefore = (int) $pdo->query('SELECT COUNT(*) FROM ai_interaction')->fetchColumn();
ai_provider(new MockProvider());

$exam = exam_start(1);

check('the examination is built',      $exam['ok'] === true);
check('it has the full question count', $exam['items'] === EXAM_QUESTION_COUNT);
check('it names the topics it covers',  count($exam['topics']) === 3);
check('an attempt was started',         is_int($exam['attempt_id']) && $exam['attempt_id'] > 0);

check('NOTHING was generated, so it cost nothing',
    $exam['generated'] === 0);
check('and no provider call was made',
    (int) $pdo->query('SELECT COUNT(*) FROM ai_interaction')->fetchColumn() === $callsBefore);
check('every question came from stock', $exam['reused'] === EXAM_QUESTION_COUNT);

section('What the examination row looks like');

$activity = $pdo->query('SELECT * FROM learning_activity WHERE activity_id = '
    . (int) $exam['activity_id'])->fetch();

check('it is typed as a mock examination',
    (string) $activity['activity_type'] === EXAM_ACTIVITY_TYPE);
check('exam_is_exam agrees',        exam_is_exam((string) $activity['activity_type']) === true);
check('a practice set does not',    exam_is_exam('practice_set') === false);
check('it belongs to no single topic', $activity['topic_progress_id'] === null);
check('its title names the topics',
    str_contains((string) $activity['title'], 'Fourier Series'));

check('every item carries its own topic', (function () use ($pdo, $exam) {
    $rows = $pdo->query('SELECT topic_progress_id FROM activity_item
                          WHERE activity_id = ' . (int) $exam['activity_id'])->fetchAll();
    foreach ($rows as $row) {
        if ($row['topic_progress_id'] === null) { return false; }
    }
    return count($rows) === EXAM_QUESTION_COUNT;
})());

check('every item carries its own Bloom level', (function () use ($pdo, $exam) {
    $rows = $pdo->query('SELECT bloom_level FROM activity_item
                          WHERE activity_id = ' . (int) $exam['activity_id'])->fetchAll();
    foreach ($rows as $row) {
        if (trim((string) $row['bloom_level']) === '') { return false; }
    }
    return true;
})());

check('the items span more than one topic', (function () use ($pdo, $exam) {
    $n = (int) $pdo->query('SELECT COUNT(DISTINCT topic_progress_id) FROM activity_item
                             WHERE activity_id = ' . (int) $exam['activity_id'])->fetchColumn();
    return $n === 3;
})());

check('the items span more than one Bloom level', (function () use ($pdo, $exam) {
    $n = (int) $pdo->query('SELECT COUNT(DISTINCT bloom_level) FROM activity_item
                             WHERE activity_id = ' . (int) $exam['activity_id'])->fetchColumn();
    return $n > 1;
})());

/* ------------------------------------------------- never reuse an answer */

section('A question already answered is never reused');

check('answered questions are excluded from the next examination',
    (function () use ($pdo, $exam, $fourier) {
        // Answer everything in the first examination.
        $items = $pdo->query('SELECT item_id FROM activity_item WHERE activity_id = '
            . (int) $exam['activity_id'])->fetchAll();
        foreach ($items as $item) {
            $pdo->prepare('INSERT INTO attempt_response (attempt_id, item_id, user_answer, is_correct)
                           VALUES (?, ?, ?, 1)')
                ->execute([(int) $exam['attempt_id'], (int) $item['item_id'], 'x']);
        }
        /* Nothing this learner has been asked may come back, and that means the
           ORIGINAL of a question they answered as an exam COPY. An id-based
           check passed here while the same question was being served again. */
        foreach (exam_unseen_items(1, $fourier, 50) as $candidate) {
            $asked = (int) $pdo->query(
                'SELECT COUNT(*) FROM attempt_response ar
                   JOIN activity_attempt aa ON aa.attempt_id = ar.attempt_id
                   JOIN activity_item asked ON asked.item_id = ar.item_id
                  WHERE aa.user_id = 1 AND asked.question_text = '
                . $pdo->quote((string) $candidate['question_text']))->fetchColumn();
            if ($asked > 0) { return false; }
        }
        return true;
    })());

check('another learner answering does not hide the question from this one',
    (function () use ($pdo, $fourier) {
        // Ownership is per learner: learner 2's answers must not shrink
        // learner 1's pool.
        $before = count(exam_unseen_items(1, $fourier, 50));
        $item = (int) $pdo->query('SELECT ai.item_id FROM activity_item ai
                                    JOIN learning_activity la ON la.activity_id = ai.activity_id
                                   WHERE la.topic_progress_id = ' . $fourier . ' LIMIT 1')
            ->fetchColumn();
        $pdo->exec("INSERT INTO activity_attempt (user_id, activity_id, total_items)
                    VALUES (2, 1, 1)");
        $other = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO attempt_response (attempt_id, item_id, user_answer, is_correct)
                       VALUES (?, ?, ?, 1)')->execute([$other, $item, 'x']);
        return count(exam_unseen_items(1, $fourier, 50)) === $before;
    })());

check('items inside an examination are not drawn into another examination',
    (function () use ($pdo, $fourier) {
        // exam_unseen_items only reads practice_set activities.
        foreach (exam_unseen_items(1, $fourier, 50) as $candidate) {
            $type = (string) $pdo->query('SELECT la.activity_type FROM activity_item ai
                                           JOIN learning_activity la ON la.activity_id = ai.activity_id
                                          WHERE ai.item_id = ' . (int) $candidate['item_id'])
                ->fetchColumn();
            if ($type !== 'practice_set') { return false; }
        }
        return true;
    })());

/* ------------------------------------------------------ generating a top-up */

section('Generating only the shortfall');

check('when stock runs out, it generates and still builds', (function () use ($pdo) {
    ai_provider(new MockProvider());
    $before = (int) $pdo->query('SELECT COUNT(*) FROM ai_interaction')->fetchColumn();
    $second = exam_start(1);
    $after  = (int) $pdo->query('SELECT COUNT(*) FROM ai_interaction')->fetchColumn();
    return $second['ok'] === true
        && $second['generated'] > 0
        && $after > $before;
})());

/* ------------------------------------------------- mastery across topics */

section('Exam answers move mastery, for every topic touched');

check('finishing an examination recalculates every topic it covered',
    (function () use ($pdo, $fourier, $nyquist, $convolution) {
        ai_provider(new MockProvider());
        $pdo->exec('UPDATE topic_progress SET mastery_score = 0, scored_items = 0');

        $exam = exam_start(1);
        if (!$exam['ok']) { return false; }

        // Answer every question correctly.
        $items = $pdo->query('SELECT item_id FROM activity_item WHERE activity_id = '
            . (int) $exam['activity_id'])->fetchAll();
        foreach ($items as $item) {
            attempt_answer((int) $exam['attempt_id'], (int) $item['item_id'], null, 1);
        }
        $pdo->exec('UPDATE attempt_response SET is_correct = 1 WHERE attempt_id = '
            . (int) $exam['attempt_id']);

        $finish = attempt_finish((int) $exam['attempt_id'], 1);
        if (!$finish['ok']) { return false; }

        // Every topic the exam covered must now have scored items.
        foreach ([$fourier, $nyquist, $convolution] as $topicId) {
            $row = $pdo->query('SELECT scored_items FROM topic_progress
                                 WHERE topic_progress_id = ' . $topicId)->fetch();
            if ((int) $row['scored_items'] === 0) { return false; }
        }
        return true;
    })());

check('attempt_topics_touched reports one topic for a practice set',
    (function () use ($pdo, $fourier, $stockSet) {
        $activityId = $stockSet($fourier, 'Remember', 2);
        $pdo->prepare('INSERT INTO activity_attempt (user_id, activity_id, total_items)
                       VALUES (1, ?, 2)')->execute([$activityId]);
        $attemptId = (int) $pdo->lastInsertId();
        $item = (int) $pdo->query('SELECT item_id FROM activity_item WHERE activity_id = '
            . $activityId . ' LIMIT 1')->fetchColumn();
        $pdo->prepare('INSERT INTO attempt_response (attempt_id, item_id, user_answer, is_correct)
                       VALUES (?, ?, ?, 1)')->execute([$attemptId, $item, 'x']);
        return attempt_topics_touched($attemptId, 1) === [$fourier];
    })());

check('an unanswered examination touches no topic', (function () use ($pdo) {
    ai_provider(new MockProvider());
    $fresh = exam_start(1);
    return $fresh['ok'] === true
        && attempt_topics_touched((int) $fresh['attempt_id'], 1) === [];
})());

/* --------------------------------------------------------- the breakdown */

section('The per-topic breakdown on the result screen');

check('it reports one row per topic answered', (function () use ($pdo) {
    ai_provider(new MockProvider());
    $pdo->exec('UPDATE topic_progress SET mastery_score = 0, scored_items = 0');
    $exam = exam_start(1);
    if (!$exam['ok']) { return false; }

    $items = $pdo->query('SELECT item_id, topic_progress_id FROM activity_item
                           WHERE activity_id = ' . (int) $exam['activity_id'])->fetchAll();
    foreach ($items as $item) {
        attempt_answer((int) $exam['attempt_id'], (int) $item['item_id'], null, 1);
    }

    $rows = exam_topic_breakdown((int) $exam['attempt_id'], 1);
    $distinct = (int) $pdo->query('SELECT COUNT(DISTINCT topic_progress_id) FROM activity_item
                                    WHERE activity_id = ' . (int) $exam['activity_id'])->fetchColumn();
    if (count($rows) !== $distinct) { return false; }

    $answered = 0;
    foreach ($rows as $row) { $answered += $row['answered']; }
    return $answered === count($items);
})());

check('another learner gets no breakdown for it', (function () use ($pdo) {
    $attemptId = (int) $pdo->query('SELECT attempt_id FROM activity_attempt
                                     WHERE user_id = 1 ORDER BY attempt_id DESC LIMIT 1')
        ->fetchColumn();
    return exam_topic_breakdown($attemptId, 2) === [];
})());

/* ----------------------------------------------------------------- listing */

section('Listing past examinations');

$mine = exam_list(1, 20);
check('examinations are listed',    count($mine) > 0);
check('only examinations, no practice sets', (function () use ($pdo, $mine) {
    foreach ($mine as $row) {
        $type = (string) $pdo->query('SELECT activity_type FROM learning_activity
                                       WHERE activity_id = ' . (int) $row['activity_id'])
            ->fetchColumn();
        if ($type !== EXAM_ACTIVITY_TYPE) { return false; }
    }
    return true;
})());
check('another learner sees none of them', exam_list(2, 20) === []);

echo "\n==============================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
