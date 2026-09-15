<?php
/**
 * EduFlex — AI-output validation rubric run.
 *
 *     php tools/rubric_run.php --sets=12
 *     php tools/rubric_run.php --sets=2 --allow-mock     (harness check only)
 *
 * This produces the Chapter IV numbers: how many questions a real language
 * model returned, how many `questions_validate()` discarded, and exactly why.
 * Nothing else in EduFlex produces those figures, and they cannot be produced
 * against the mock provider, because the mock is written to return valid
 * questions and would report a 0 percent rejection rate that means nothing.
 *
 * READ BEFORE RUNNING. This is the only script in the repository that costs
 * money. Each set is one provider call. Twelve sets is twelve calls.
 *
 * What it does NOT do: it never deletes anything, and it never touches a topic
 * belonging to another account. Generated sets are stored like any other, so a
 * rubric run leaves the learner with real practice material rather than
 * throwaway rows.
 *
 * Output is a Markdown table written to stdout and, with --out, to a file ready
 * to paste into the manuscript.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This script runs from the command line only.\n");
}

require_once __DIR__ . '/../includes/questions.php';
require_once __DIR__ . '/../includes/rubric.php';
require_once __DIR__ . '/../includes/auth.php';

/* -------------------------------------------------------------------------
   Arguments
   ------------------------------------------------------------------------- */

$options = getopt('', ['sets::', 'user::', 'bloom::', 'out::', 'allow-mock', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<TEXT

    EduFlex AI-output validation rubric run

      --sets=N       how many question sets to generate (default 12)
      --user=ID      which learner's material to use (default: the only one,
                     or the one with the most processed material)
      --bloom=LEVEL  force a Bloom level instead of letting EduFlex choose
      --out=FILE     also write the Markdown report here
      --allow-mock   run against the mock provider to check the harness itself.
                     The numbers are meaningless for Chapter IV.

    Each set is one provider call. This is the only script here that spends
    quota.

    TEXT);
    exit(0);
}

$setsWanted = max(1, min(100, (int) ($options['sets'] ?? 12)));
$forceBloom = isset($options['bloom']) ? (string) $options['bloom'] : null;
$outFile    = isset($options['out']) ? (string) $options['out'] : null;
$allowMock  = isset($options['allow-mock']);

if ($forceBloom !== null && !in_array($forceBloom, BLOOM_ORDER, true)) {
    fwrite(STDERR, "Unknown Bloom level: $forceBloom\n"
                 . 'Use one of: ' . implode(', ', BLOOM_ORDER) . "\n");
    exit(2);
}

/* -------------------------------------------------------------------------
   Refuse to produce meaningless numbers

   The whole point of this run is to measure a real model's output. The mock
   returns questions built to pass the validator, so running against it reports
   a rejection rate of zero that says nothing about anything.
   ------------------------------------------------------------------------- */

$driver = defined('AI_DRIVER') ? AI_DRIVER : 'mock';
$keySet = defined('AI_API_KEY') && trim((string) AI_API_KEY) !== '';

if ($driver === 'mock' && !$allowMock) {
    fwrite(STDERR, <<<TEXT

    REFUSING TO RUN: AI_DRIVER is 'mock'.

    The mock provider returns questions written to pass questions_validate(),
    so this run would report that nothing was discarded. That number is not
    Chapter IV data; it is a measurement of the mock.

    To produce real figures, open config/ai.php and set:

        AI_DRIVER   to 'openai-compatible' or 'gemini'
        AI_API_KEY  to a real key
        AI_MODEL    to the model you intend to name in the manuscript

    then run this again. See AI-OPTIONS.md for provider choice.

    To check that this harness itself works, without spending anything:

        php tools/rubric_run.php --sets=2 --allow-mock


    TEXT);
    exit(3);
}

if ($driver !== 'mock' && !$keySet) {
    fwrite(STDERR, "REFUSING TO RUN: AI_DRIVER is '$driver' but AI_API_KEY is empty.\n"
                 . "Set the key in config/ai.php.\n");
    exit(3);
}

/* -------------------------------------------------------------------------
   Pick a learner and their generatable topics
   ------------------------------------------------------------------------- */

try {
    $pdo = db();
    $pdo->query('SELECT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot reach the database. Is MySQL running in XAMPP?\n");
    exit(4);
}

$userId = isset($options['user']) ? (int) $options['user'] : 0;

if ($userId <= 0) {
    // The account with the most processed material, which on a development
    // machine is almost always the only one.
    $stmt = $pdo->query(
        "SELECT u.user_id, u.full_name, COUNT(lr.resource_id) AS materials
           FROM user u
           JOIN learning_resource lr
             ON lr.user_id = u.user_id AND lr.processing_status = 'processed'
       GROUP BY u.user_id, u.full_name
       ORDER BY materials DESC, u.user_id ASC
          LIMIT 1"
    );
    $row = $stmt->fetch();
    if (!$row) {
        fwrite(STDERR, <<<TEXT

        No account has any processed material, so there is nothing to generate
        questions from.

        Sign in, upload a real document, let EduFlex read it, run topic
        detection on it, and then run this again. Use your own manuscript or a
        lecture handout: the point of this measurement is how the model behaves
        on the material the study actually uses.

        TEXT);
        exit(5);
    }
    $userId = (int) $row['user_id'];
}

$stmt = $pdo->prepare('SELECT full_name, email FROM user WHERE user_id = ? LIMIT 1');
$stmt->execute([$userId]);
$learner = $stmt->fetch();

if (!$learner) {
    fwrite(STDERR, "No account with user_id $userId.\n");
    exit(5);
}

$topics = questions_generatable_topics($userId, 200);

if (!$topics) {
    fwrite(STDERR, "That account has no topics on processed material.\n"
                 . "Run topic detection on an uploaded document first.\n");
    exit(5);
}

/* -------------------------------------------------------------------------
   Confirm before spending

   A mistyped --sets=120 is 120 provider calls. Ask once.
   ------------------------------------------------------------------------- */

printf("\nEduFlex AI-output validation rubric run\n");
printf("======================================\n\n");
printf("  Provider   %s\n", $driver);
printf("  Model      %s\n", defined('AI_MODEL') ? AI_MODEL : '(unset)');
printf("  Learner    %s (user_id %d)\n", (string) $learner['full_name'], $userId);
printf("  Topics     %d available\n", count($topics));
printf("  Sets       %d, one provider call each\n", $setsWanted);
printf("  Bloom      %s\n\n", $forceBloom ?? 'chosen per topic by EduFlex');

if (!$allowMock) {
    printf("This will make %d call%s to %s and will cost quota. Continue? [y/N] ",
        $setsWanted, $setsWanted === 1 ? '' : 's', $driver);
    $answer = trim((string) fgets(STDIN));
    if (strtolower($answer) !== 'y') {
        printf("\nStopped. Nothing was called.\n");
        exit(0);
    }
    printf("\n");
}

/* -------------------------------------------------------------------------
   The run
   ------------------------------------------------------------------------- */

$results   = [];
$startedAt = microtime(true);

for ($i = 0; $i < $setsWanted; $i++) {
    // Round-robin through the topics, so a learner with three topics and a
    // request for twelve sets gets four sets each rather than twelve of one.
    $topic     = $topics[$i % count($topics)];
    $topicId   = (int) $topic['topic_progress_id'];
    $topicName = (string) $topic['topic_name'];

    printf("  [%2d/%2d] %-44s ", $i + 1, $setsWanted, mb_strimwidth($topicName, 0, 44, '...'));

    $began  = microtime(true);
    $result = questions_generate($topicId, $userId, $forceBloom);
    $tookMs = (int) round((microtime(true) - $began) * 1000);

    // Everything the summary needs, plus the two things questions_generate()
    // has no way to know: which topic this was, and how long it took.
    $result['topic'] = $topicName;
    $result['ms']    = $tookMs;
    $results[]       = $result;

    $returned = (int) $result['items'] + (int) $result['rejected'];

    if (!$result['ok'] && $returned === 0) {
        printf("PROVIDER FAILED  %s\n", (string) $result['error']);
        continue;
    }

    printf("%s  %d/%d kept  %s  %dms\n",
        $result['ok'] ? 'stored ' : 'DROPPED',
        (int) $result['items'],
        $returned,
        str_pad((string) $result['bloom'], 10),
        $tookMs
    );
}

/* -------------------------------------------------------------------------
   The report

   Both the arithmetic and the Markdown live in includes/rubric.php, so the
   numbers that reach Chapter IV come from something tests/rubric_test.php
   asserts rather than from a script nobody can run twice.
   ------------------------------------------------------------------------- */

$summary = rubric_summarise($results);
$report  = rubric_report($summary, [
    'driver'  => $driver,
    'model'   => defined('AI_MODEL') ? AI_MODEL : '(unset)',
    'elapsed' => (string) round(microtime(true) - $startedAt, 1),
    'mock'    => $driver === 'mock',
]);

printf("\n%s\n", str_repeat('-', 62));
printf("%s", $report);

if ($outFile !== null) {
    if (@file_put_contents($outFile, $report) === false) {
        fwrite(STDERR, "\nCould not write $outFile\n");
        exit(6);
    }
    printf("\nWritten to %s\n", $outFile);
}

exit(0);
