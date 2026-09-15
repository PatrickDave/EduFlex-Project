<?php
/**
 * EduFlex — the arithmetic and reporting behind the AI-output validation
 * rubric run.
 *
 * Separate from tools/rubric_run.php on purpose. That script spends quota and
 * cannot run in a test suite; these two functions are pure, take the per-set
 * results as data, and are asserted in tests/rubric_test.php.
 *
 * That split matters because these numbers go into Chapter IV. A figure in a
 * manuscript should come from arithmetic somebody has checked, not from a
 * one-off script nobody could run twice.
 *
 * One rule runs through both functions: a provider call that failed is not a
 * validation result. A set the model never returned says nothing about the
 * quality of its questions, so it is counted separately and kept out of every
 * rate.
 */

declare(strict_types=1);

require_once __DIR__ . '/questions.php';

/**
 * Aggregate the per-set results of a rubric run.
 *
 * Each entry of $results is one call to questions_generate(), in the shape that
 * function returns, plus an optional 'ms'.
 *
 * @param list<array<string,mixed>> $results
 * @return array<string,mixed>
 */
function rubric_summarise(array $results): array
{
    $rows          = [];
    $reasonTotals  = [];
    $returnedTotal = 0;
    $acceptedTotal = 0;
    $rejectedTotal = 0;
    $setsStored    = 0;
    $setsDiscarded = 0;
    $callsFailed   = 0;

    foreach ($results as $i => $result) {
        $accepted = (int) ($result['items'] ?? 0);
        $rejected = (int) ($result['rejected'] ?? 0);
        $returned = $accepted + $rejected;
        $ok       = (bool) ($result['ok'] ?? false);

        /* A provider failure, as distinct from a set the model returned and the
           validator refused. The test for it is that nothing came back at all:
           questions_generate() reports ok=false in both cases, so the count is
           what separates "the call failed" from "the questions were bad". */
        $providerFailed = !$ok && $returned === 0;

        if ($providerFailed) {
            $callsFailed++;
        } else {
            $returnedTotal += $returned;
            $acceptedTotal += $accepted;
            $rejectedTotal += $rejected;

            if ($ok) {
                $setsStored++;
            } else {
                $setsDiscarded++;
            }

            foreach ((array) ($result['reasons'] ?? []) as $why => $count) {
                $reasonTotals[(string) $why] = ($reasonTotals[(string) $why] ?? 0) + (int) $count;
            }
        }

        $rows[] = [
            'n'        => $i + 1,
            'topic'    => (string) ($result['topic'] ?? ''),
            'bloom'    => (string) ($result['bloom'] ?? ''),
            'returned' => $providerFailed ? 0 : $returned,
            'accepted' => $providerFailed ? 0 : $accepted,
            'rejected' => $providerFailed ? 0 : $rejected,
            'stored'   => $ok,
            'failed'   => $providerFailed,
            'ms'       => (int) ($result['ms'] ?? 0),
            'error'    => (string) ($result['error'] ?? ''),
        ];
    }

    // Sets that produced a validation result, which is the denominator for the
    // set-level rates. A failed call is not one of them.
    $setsJudged = $setsStored + $setsDiscarded;

    arsort($reasonTotals);

    return [
        'rows'           => $rows,
        'reasons'        => $reasonTotals,
        'returned'       => $returnedTotal,
        'accepted'       => $acceptedTotal,
        'rejected'       => $rejectedTotal,
        'sets_attempted' => count($results),
        'sets_judged'    => $setsJudged,
        'sets_stored'    => $setsStored,
        'sets_discarded' => $setsDiscarded,
        'calls_failed'   => $callsFailed,
        'accept_rate'    => rubric_percent($acceptedTotal, $returnedTotal),
        'reject_rate'    => rubric_percent($rejectedTotal, $returnedTotal),
        'set_store_rate' => rubric_percent($setsStored, $setsJudged),
        'set_drop_rate'  => rubric_percent($setsDiscarded, $setsJudged),
    ];
}

/**
 * A percentage, with an empty denominator reported as 0 rather than as a
 * division by zero or a NAN that would reach the manuscript as "NAN%".
 */
function rubric_percent(int $part, int $whole): float
{
    if ($whole <= 0) {
        return 0.0;
    }
    return round(($part / $whole) * 100, 1);
}

/**
 * Render the summary as Markdown, ready to paste into Chapter IV.
 *
 * @param array<string,mixed> $summary from rubric_summarise()
 * @param array<string,mixed> $meta    provider, model, elapsed, mock
 */
function rubric_report(array $summary, array $meta = []): string
{
    $driver  = (string) ($meta['driver'] ?? 'unknown');
    $model   = (string) ($meta['model'] ?? '(unset)');
    $elapsed = (string) ($meta['elapsed'] ?? '0');
    $isMock  = (bool) ($meta['mock'] ?? false);
    $when    = (string) ($meta['when'] ?? date('j F Y \a\t H:i'));

    $out  = "# AI-output validation rubric run\n\n";
    $out .= sprintf("Run on %s.\n\n", $when);

    $out .= "| Setting | Value |\n|---|---|\n";
    $out .= sprintf("| Provider | `%s` |\n", $driver);
    $out .= sprintf("| Model | `%s` |\n", $model);
    $out .= sprintf("| Questions requested per set | %d |\n", QUESTIONS_PER_SET);
    $out .= sprintf("| Minimum usable to store a set | %d |\n", QUESTIONS_MIN_VALID);
    $out .= sprintf("| Sets attempted | %d |\n", (int) $summary['sets_attempted']);
    $out .= sprintf("| Provider calls | %d |\n", (int) $summary['sets_attempted']);
    $out .= sprintf("| Wall clock | %s seconds |\n", $elapsed);
    if ($isMock) {
        $out .= "| **Warning** | **Mock provider. These numbers measure the harness, "
              . "not a model, and must not be reported in Chapter IV.** |\n";
    }
    $out .= "\n";

    $out .= "## Results\n\n";
    $out .= "| Measure | Count | Share |\n|---|---:|---:|\n";
    $out .= sprintf("| Questions returned by the model | %d | 100%% |\n", (int) $summary['returned']);
    $out .= sprintf("| Accepted by `questions_validate()` | %d | %.1f%% |\n",
        (int) $summary['accepted'], (float) $summary['accept_rate']);
    $out .= sprintf("| Discarded by `questions_validate()` | %d | %.1f%% |\n",
        (int) $summary['rejected'], (float) $summary['reject_rate']);
    $out .= sprintf("| Sets stored | %d | %.1f%% |\n",
        (int) $summary['sets_stored'], (float) $summary['set_store_rate']);
    $out .= sprintf("| Sets discarded entirely (under %d usable) | %d | %.1f%% |\n",
        QUESTIONS_MIN_VALID, (int) $summary['sets_discarded'], (float) $summary['set_drop_rate']);
    $out .= sprintf("| Provider calls that failed outright | %d | |\n", (int) $summary['calls_failed']);
    $out .= "\n";

    $out .= "## Why questions were discarded\n\n";
    if ($summary['reasons'] === []) {
        $out .= "Nothing was discarded in this run.\n\n";
    } else {
        $out .= "| Reason | Count | Share of discarded |\n|---|---:|---:|\n";
        foreach ($summary['reasons'] as $why => $count) {
            $out .= sprintf("| %s | %d | %.1f%% |\n",
                rubric_escape_cell((string) $why),
                (int) $count,
                rubric_percent((int) $count, (int) $summary['rejected']));
        }
        $out .= "\n";

        $critical = (int) ($summary['reasons']['answer is not one of the options'] ?? 0);
        if ($critical > 0) {
            $out .= sprintf(
                "The %d question%s rejected for \"answer is not one of the options\" %s the "
                . "ones that matter most. Each would have marked a learner wrong for choosing "
                . "the right answer, and would have corrupted that topic's mastery score "
                . "permanently, because `mastery_recalculate()` reads every stored answer. "
                . "See README section 4.\n\n",
                $critical,
                $critical === 1 ? '' : 's',
                $critical === 1 ? 'is' : 'are'
            );
        }
    }

    $out .= "## Per set\n\n";
    $out .= "| # | Topic | Bloom | Returned | Kept | Discarded | Outcome | ms |\n";
    $out .= "|---:|---|---|---:|---:|---:|---|---:|\n";
    foreach ($summary['rows'] as $r) {
        $out .= sprintf("| %d | %s | %s | %d | %d | %d | %s | %d |\n",
            (int) $r['n'],
            rubric_escape_cell((string) $r['topic']),
            $r['bloom'] !== '' ? rubric_escape_cell((string) $r['bloom']) : '-',
            (int) $r['returned'],
            (int) $r['accepted'],
            (int) $r['rejected'],
            $r['failed'] ? 'provider failed' : ($r['stored'] ? 'stored' : 'set discarded'),
            (int) $r['ms']
        );
    }
    $out .= "\n";

    $out .= "## How to read this\n\n";
    $out .= "`questions_validate()` in `includes/questions.php` rejects a question when its\n";
    $out .= "stated answer is not among its own options, when it does not have exactly "
          . QUESTION_OPTION_COUNT . "\n";
    $out .= "options, when two options are identical, when an option says \"all of the above\"\n";
    $out .= "or \"none of the above\", when the same question already appeared in the set, or\n";
    $out .= "when the question text is missing or trivially short. A set with fewer than "
          . QUESTIONS_MIN_VALID . "\n";
    $out .= "usable questions is discarded whole rather than shown to a learner.\n\n";
    $out .= "The discarded share is the figure Chapter IV reports. It is the evidence that\n";
    $out .= "the validation layer does something, which is the claim the study makes about it.\n";

    return $out;
}

/**
 * Keep a topic name containing a pipe from breaking the Markdown table it is
 * rendered into. Newlines would do the same, so they go too.
 */
function rubric_escape_cell(string $value): string
{
    $value = str_replace(["\r\n", "\r", "\n"], ' ', $value);
    return str_replace('|', '\\|', $value);
}
