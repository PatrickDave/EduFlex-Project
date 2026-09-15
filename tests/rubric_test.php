<?php
/**
 * EduFlex — rubric run arithmetic test suite.
 *
 *     php tests/rubric_test.php
 *
 * No database, no network, no provider. rubric_summarise() and rubric_report()
 * are pure: they take the per-set results as data and return numbers and
 * Markdown.
 *
 * This suite exists because these figures go into Chapter IV. A percentage in a
 * manuscript should come from arithmetic somebody has checked, not from a
 * one-off script that spends money and cannot be run twice the same way.
 *
 * The cases that matter most are the ones a run against the mock provider can
 * never produce, because the mock returns questions built to pass validation:
 * discarded questions, discarded whole sets, and provider calls that failed.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/rubric.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "  PASS  $label\n"; }
    else            { $failed++; echo "  FAIL  $label\n"; }
}
function section(string $name): void { echo "\n$name\n"; }

echo "EduFlex rubric run suite\n========================\n";

/** One result in the shape questions_generate() returns. */
function set_result(
    bool $ok,
    int $items,
    int $rejected,
    array $reasons = [],
    string $topic = 'Fourier Series',
    string $bloom = 'Understand',
    ?string $error = null
): array {
    return [
        'ok'          => $ok,
        'activity_id' => $ok ? 1 : null,
        'items'       => $items,
        'rejected'    => $rejected,
        'reasons'     => $reasons,
        'bloom'       => $bloom,
        'topic'       => $topic,
        'ms'          => 1200,
        'error'       => $error,
    ];
}

/* ------------------------------------------------------------ empty input */

section('Nothing to summarise');

$empty = rubric_summarise([]);
check('no sets attempted',      $empty['sets_attempted'] === 0);
check('no questions returned',  $empty['returned'] === 0);
check('no reasons',             $empty['reasons'] === []);
check('rates are zero, not NAN', $empty['reject_rate'] === 0.0 && $empty['accept_rate'] === 0.0);
check('a report is still produced', str_contains(rubric_report($empty), 'rubric run'));
check('and it does not print NAN', !str_contains(rubric_report($empty), 'NAN'));

/* ------------------------------------------------------- the ordinary case */

section('A clean run');

$clean = rubric_summarise([
    set_result(true, 8, 0),
    set_result(true, 8, 0),
    set_result(true, 8, 0),
]);
check('every set is counted',     $clean['sets_attempted'] === 3);
check('every set was stored',     $clean['sets_stored'] === 3);
check('none was discarded',       $clean['sets_discarded'] === 0);
check('24 questions returned',    $clean['returned'] === 24);
check('24 accepted',              $clean['accepted'] === 24);
check('none rejected',            $clean['rejected'] === 0);
check('the accept rate is 100',   $clean['accept_rate'] === 100.0);
check('the reject rate is 0',     $clean['reject_rate'] === 0.0);

/* ---------------------------------------------------- questions discarded */

section('Questions discarded, sets still stored');

$mixed = rubric_summarise([
    set_result(true, 6, 2, ['answer is not one of the options' => 1, 'duplicate options' => 1]),
    set_result(true, 7, 1, ['duplicate question' => 1]),
    set_result(true, 5, 3, ['answer is not one of the options' => 2, 'wrong number of options' => 1]),
]);

check('returned is accepted plus rejected', $mixed['returned'] === 24);
check('accepted is summed',                 $mixed['accepted'] === 18);
check('rejected is summed',                 $mixed['rejected'] === 6);
check('the reject rate is 25 percent',      $mixed['reject_rate'] === 25.0);
check('the accept rate is 75 percent',      $mixed['accept_rate'] === 75.0);
check('the two rates add to 100',
    $mixed['accept_rate'] + $mixed['reject_rate'] === 100.0);

check('reasons are merged across sets',
    $mixed['reasons']['answer is not one of the options'] === 3);
check('every distinct reason is kept',      count($mixed['reasons']) === 4);
check('the reason counts sum to the rejected total',
    array_sum($mixed['reasons']) === $mixed['rejected']);

check('reasons are ordered with the commonest first', (function () use ($mixed) {
    // The report renders them in this order, so the biggest cause of rejection
    // is the first thing a reader sees.
    $counts = array_values($mixed['reasons']);
    for ($i = 1; $i < count($counts); $i++) {
        if ($counts[$i] > $counts[$i - 1]) { return false; }
    }
    return $counts !== [] && $counts[0] === 3;
})());

/* --------------------------------------------------- a whole set discarded */

section('A whole set discarded');

$dropped = rubric_summarise([
    set_result(true, 8, 0),
    // Three usable is under QUESTIONS_MIN_VALID, so the set is refused even
    // though the model returned eight questions and three were fine.
    set_result(false, 3, 5, ['answer is not one of the options' => 5], 'Nyquist', 'Apply',
        'Only 3 of the generated questions were usable.'),
]);

check('both sets were attempted',      $dropped['sets_attempted'] === 2);
check('one was stored',                $dropped['sets_stored'] === 1);
check('one was discarded',             $dropped['sets_discarded'] === 1);
check('no provider call failed',       $dropped['calls_failed'] === 0);
check('the drop rate is 50 percent',   $dropped['set_drop_rate'] === 50.0);
check('the store rate is 50 percent',  $dropped['set_store_rate'] === 50.0);

check('the discarded set still counts toward the question totals',
    $dropped['returned'] === 16 && $dropped['accepted'] === 11 && $dropped['rejected'] === 5);
check('its rejection reasons are still counted',
    $dropped['reasons']['answer is not one of the options'] === 5);

check('the row says the set was discarded, not that the call failed', (function () use ($dropped) {
    $row = $dropped['rows'][1];
    return $row['stored'] === false && $row['failed'] === false && $row['returned'] === 8;
})());

/* ------------------------------------------------- a provider call failing */

section('A provider call that failed is not a validation result');

$withFailure = rubric_summarise([
    set_result(true, 8, 0),
    set_result(true, 6, 2, ['duplicate options' => 2]),
    // Nothing came back at all: a timeout, a refused key, a 500.
    set_result(false, 0, 0, [], 'Nyquist', '', 'The request to the AI provider timed out.'),
]);

check('the failed call is counted separately', $withFailure['calls_failed'] === 1);
check('it is not counted as a discarded set',  $withFailure['sets_discarded'] === 0);
check('it is not counted as a stored set',     $withFailure['sets_stored'] === 1 + 1);
check('sets_judged excludes it',               $withFailure['sets_judged'] === 2);
check('sets_attempted includes it',            $withFailure['sets_attempted'] === 3);

// Two sets arrived with 8 questions each, so 16 returned, 14 kept, 2 rejected.
// The third call returned nothing and contributes to none of those.
check('it does not dilute the question totals',
    $withFailure['returned'] === 16 && $withFailure['accepted'] === 14);
check('the reject rate is computed only over questions that arrived',
    $withFailure['reject_rate'] === 12.5);

check('a set that returned nothing would otherwise have shown as 0 percent rejected',
    // The point of the rule: counting a failed call as a judged set with zero
    // rejections would make the model look better the more often it broke.
    $withFailure['reject_rate'] > 0.0);

check('the row is marked as a provider failure', (function () use ($withFailure) {
    $row = $withFailure['rows'][2];
    return $row['failed'] === true && $row['returned'] === 0 && $row['stored'] === false;
})());

check('every call failing leaves every rate at zero rather than NAN', (function () {
    $s = rubric_summarise([
        set_result(false, 0, 0, [], 'A', '', 'timeout'),
        set_result(false, 0, 0, [], 'B', '', 'timeout'),
    ]);
    return $s['calls_failed'] === 2
        && $s['sets_judged'] === 0
        && $s['reject_rate'] === 0.0
        && $s['set_drop_rate'] === 0.0
        && !str_contains(rubric_report($s), 'NAN');
})());

/* ---------------------------------------------------------------- percent */

section('Percentages');

check('a zero denominator gives zero', rubric_percent(5, 0) === 0.0);
check('a negative denominator gives zero', rubric_percent(5, -3) === 0.0);
check('a half is 50',                  rubric_percent(1, 2) === 50.0);
check('a third rounds to one place',   rubric_percent(1, 3) === 33.3);
check('two thirds rounds up',          rubric_percent(2, 3) === 66.7);
check('nothing of something is zero',  rubric_percent(0, 9) === 0.0);
check('all of something is 100',       rubric_percent(9, 9) === 100.0);

/* ----------------------------------------------------------------- report */

section('The report');

$report = rubric_report($mixed, [
    'driver'  => 'openai-compatible',
    'model'   => 'gpt-4o-mini',
    'elapsed' => '31.4',
    'mock'    => false,
    'when'    => '15 September 2026 at 10:00',
]);

check('it names the provider',       str_contains($report, 'openai-compatible'));
check('it names the model',          str_contains($report, 'gpt-4o-mini'));
check('it reports the reject rate',  str_contains($report, '25.0%'));
check('it lists every reason',       str_contains($report, 'duplicate question'));
check('it reports the wall clock',   str_contains($report, '31.4'));
check('it has a per-set table',      str_contains($report, '## Per set'));
check('it explains what the validator rejects',
    str_contains($report, 'all of the above'));
check('a real run carries no mock warning',
    !str_contains($report, 'Mock provider'));

check('the critical rejection is called out by name', (function () use ($report) {
    return str_contains($report, 'answer is not one of the options')
        && str_contains($report, 'marked a learner wrong for choosing');
})());

check('a run with no answer-not-in-options rejections omits that paragraph', (function () {
    $s = rubric_summarise([set_result(true, 7, 1, ['duplicate options' => 1])]);
    return !str_contains(rubric_report($s), 'marked a learner wrong');
})());

check('the critical paragraph reads correctly for a single question', (function () {
    $s = rubric_summarise([set_result(true, 7, 1, ['answer is not one of the options' => 1])]);
    $r = rubric_report($s);
    return str_contains($r, 'The 1 question rejected')
        && str_contains($r, 'is the')
        && !str_contains($r, 'question s');
})());

section('The mock warning');

$mockReport = rubric_report($clean, ['driver' => 'mock', 'mock' => true]);
check('a mock run is labelled',   str_contains($mockReport, 'Mock provider'));
check('and says not to report it', str_contains($mockReport, 'must not be reported in Chapter IV'));

section('Markdown cannot be broken by the data');

check('a pipe in a topic name is escaped', (function () {
    $s = rubric_summarise([set_result(true, 8, 0, [], 'Signals | Systems')]);
    $r = rubric_report($s);
    return str_contains($r, 'Signals \\| Systems');
})());

check('a newline in a topic name does not split the row', (function () {
    $s = rubric_summarise([set_result(true, 8, 0, [], "Two\nLines")]);
    $r = rubric_report($s);
    // The per-set table must still have exactly one row for the one set.
    $rows = array_filter(
        explode("\n", $r),
        static fn($line) => str_starts_with(trim($line), '| 1 |')
    );
    return count($rows) === 1 && str_contains($r, 'Two Lines');
})());

check('a pipe in a rejection reason is escaped', (function () {
    $s = rubric_summarise([set_result(true, 7, 1, ['odd | reason' => 1])]);
    return str_contains(rubric_report($s), 'odd \\| reason');
})());

/* ----------------------------------------------------------- consistency */

section('The report agrees with the summary');

check('accepted plus rejected equals returned, in every case', (function () {
    foreach ([
        [set_result(true, 8, 0)],
        [set_result(true, 4, 4, ['duplicate options' => 4])],
        [set_result(false, 2, 6, ['answer missing' => 6])],
        [set_result(false, 0, 0, [], 'x', '', 'timeout')],
    ] as $case) {
        $s = rubric_summarise($case);
        if ($s['accepted'] + $s['rejected'] !== $s['returned']) { return false; }
    }
    return true;
})());

check('stored plus discarded plus failed equals attempted, in every case', (function () {
    $s = rubric_summarise([
        set_result(true, 8, 0),
        set_result(false, 3, 5, ['answer missing' => 5]),
        set_result(false, 0, 0, [], 'x', '', 'timeout'),
        set_result(true, 6, 2, ['duplicate options' => 2]),
    ]);
    return $s['sets_stored'] + $s['sets_discarded'] + $s['calls_failed']
        === $s['sets_attempted'];
})());

check('one row is produced per attempted set, failures included', (function () {
    $s = rubric_summarise([
        set_result(true, 8, 0),
        set_result(false, 0, 0, [], 'x', '', 'timeout'),
        set_result(false, 1, 7, ['answer missing' => 7]),
    ]);
    return count($s['rows']) === 3
        && $s['rows'][0]['n'] === 1
        && $s['rows'][2]['n'] === 3;
})());

echo "\n========================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
