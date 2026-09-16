<?php
/**
 * EduFlex — landing page content test suite.
 *
 *     php tests/manuscript_test.php
 *
 * No database, no network, no server.
 *
 * Guards three things about the public landing page:
 *
 *   Every nav link resolves. Features, Methodology, How it Works and Resources
 *   were dead for a long time because nothing checked them. A nav item that
 *   goes nowhere is the most visible defect a panel member can find.
 *
 *   The module list matches the manuscript. Chapter III decomposes EduFlex into
 *   seven modules and 34 sub-modules, and the page is built from that list.
 *
 *   Nothing is advertised that is not built. "Manage Subscription" and
 *   "Generate Mock Examinations" were in the manuscript and not in the code
 *   until 16 September 2026, and were kept off this page until they worked.
 *   Now that both exist, each claim has to be backed by the file and the screen
 *   that provide it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/manuscript.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "  PASS  $label\n"; }
    else            { $failed++; echo "  FAIL  $label\n"; }
}
function section(string $name): void { echo "\n$name\n"; }

echo "EduFlex landing content suite\n=============================\n";

$index = (string) file_get_contents(__DIR__ . '/../index.php');

/* ------------------------------------------------------------ the nav */

section('Every nav link resolves');

/* The four items in the landing page nav. Each must have an anchor on the page
   AND a link pointing at it; either alone is a broken nav. */
foreach (['features', 'methodology', 'how', 'resources'] as $id) {
    check('#' . $id . ' has a link',   str_contains($index, 'href="#' . $id . '"'));
    check('#' . $id . ' has a target', str_contains($index, 'id="' . $id . '"'));
}

check('no nav item still points at href="#"', (function () use ($index) {
    // Comments are stripped, because index.php records in a comment that a link
    // used to be dead.
    $src = '';
    foreach (token_get_all($index) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) { continue; }
            $src .= $token[0] === T_INLINE_HTML
                ? (string) preg_replace('#<!--.*?-->#s', '', $token[1])
                : $token[1];
            continue;
        }
        $src .= $token;
    }
    return !str_contains($src, 'href="#"');
})());

/* -------------------------------------------------------------- modules */

section('The module list matches Chapter III');

check('there are seven modules', count(MANUSCRIPT_MODULES) === 7);

check('the seven are named as the manuscript names them', (function () {
    $expected = [
        'Account and Access Management',
        'Learning Resource Management',
        'AI Learning Companion',
        'Learning Activity and Assessment',
        'Adaptive Learning and Recommendations',
        'Progress and Engagement',
        'Support and Feedback',
    ];
    return array_column(MANUSCRIPT_MODULES, 'title') === $expected;
})());

check('every module has a blurb and at least four functions', (function () {
    foreach (MANUSCRIPT_MODULES as $m) {
        if (trim($m['blurb']) === '' || count($m['items']) < 4) { return false; }
    }
    return true;
})());

check('all 34 sub-modules are shown', (function () {
    $n = 0;
    foreach (MANUSCRIPT_MODULES as $m) { $n += count($m['items']); }
    return $n === 34;
})());

check('no sub-module is listed twice', (function () {
    $all = [];
    foreach (MANUSCRIPT_MODULES as $m) {
        foreach ($m['items'] as $item) { $all[] = strtolower($item); }
    }
    return count($all) === count(array_unique($all));
})());

section('Nothing is claimed that is not built');

/* These two were listed in the manuscript and missing from the code until
   16 September 2026, and were kept off this page until they worked. The
   assertions now run the other way: each is advertised, and each has to be
   backed by something real. Listing a sub-module here before it exists is the
   failure mode this section guards. */

check('"Manage subscription" is advertised', (function () {
    foreach (MANUSCRIPT_MODULES as $m) {
        foreach ($m['items'] as $item) {
            if (stripos($item, 'subscription') !== false) { return true; }
        }
    }
    return false;
})());

check('and it is backed by a real module',
    is_file(__DIR__ . '/../includes/subscription.php'));

check('and the Settings page renders it',
    str_contains((string) file_get_contents(__DIR__ . '/../app/settings.php'),
        'subscription_for('));

check('"Generate mock examinations" is advertised', (function () {
    foreach (MANUSCRIPT_MODULES as $m) {
        foreach ($m['items'] as $item) {
            if (stripos($item, 'mock exam') !== false) { return true; }
        }
    }
    return false;
})());

check('and the code really does store a second activity type', (function () {
    // The reason mock examinations can be advertised. If this ever reverts to
    // practice_set alone, the claim on the page became false.
    $exams = (string) file_get_contents(__DIR__ . '/../includes/exams.php');
    return str_contains($exams, "EXAM_ACTIVITY_TYPE = 'mock_exam'");
})());

check('and the Practice page offers one',
    str_contains((string) file_get_contents(__DIR__ . '/../app/practice.php'),
        'actions/exam_start.php'));

/* ----------------------------------------------------------- references */

section('References');

check('there are references at all', count(MANUSCRIPT_REFERENCES) > 20);
check('there are 26 after deduplication', count(MANUSCRIPT_REFERENCES) === 26);

check('none is listed twice', (function () {
    // The manuscript lists 28 entries, two of them duplicated. Those are
    // collapsed here; this fails if they creep back.
    $keys = array_map(
        static fn($r) => substr(strtolower((string) preg_replace('/\s+/', ' ', $r)), 0, 70),
        MANUSCRIPT_REFERENCES
    );
    return count($keys) === count(array_unique($keys));
})());

check('every entry carries a year', (function () {
    foreach (MANUSCRIPT_REFERENCES as $r) {
        if (!preg_match('/\((?:n\.d\.|\d{4})[a-z]?\)/', $r)) { return false; }
    }
    return true;
})());

check('every entry is a real sentence, not a stub', (function () {
    foreach (MANUSCRIPT_REFERENCES as $r) {
        if (mb_strlen(trim($r)) < 40) { return false; }
    }
    return true;
})());

check('accented characters survived the extraction', (function () {
    $all = implode(' ', MANUSCRIPT_REFERENCES);
    // Marín, Händel, Millán, Merriënboer, Küchemann, Fernández.
    return str_contains($all, 'Marín')
        && str_contains($all, 'Händel')
        && str_contains($all, 'Küchemann')
        && mb_check_encoding($all, 'UTF-8');
})());

check('the Bloom taxonomy source is cited, since the weights rest on it',
    str_contains(implode(' ', MANUSCRIPT_REFERENCES), 'Krathwohl'));

section('Rendering a reference');

check('a plain reference is escaped', (function () {
    $out = manuscript_reference_html('Smith, J. (2020). <script>alert(1)</script>.');
    return !str_contains($out, '<script>') && str_contains($out, '&lt;script&gt;');
})());

check('a DOI becomes a link', (function () {
    $out = manuscript_reference_html('Author, A. (2024). Title. https://doi.org/10.3390/x');
    return str_contains($out, '<a href="https://doi.org/10.3390/x"');
})());

check('external links open safely', (function () {
    $out = manuscript_reference_html('A. (2024). T. https://example.org/paper');
    return str_contains($out, 'rel="noopener noreferrer"')
        && str_contains($out, 'target="_blank"');
})());

check('a reference with no URL gets no link',
    !str_contains(manuscript_reference_html('Knowles, M. S. (1975). Self-Directed Learning.'), '<a '));

check('a URL cannot smuggle an attribute out of the href', (function () {
    $out = manuscript_reference_html('A (2024). https://x.test/a" onmouseover="alert(1)');
    return !str_contains($out, 'onmouseover="alert(1)"');
})());

check('four entries carry a link', (function () {
    $n = 0;
    foreach (MANUSCRIPT_REFERENCES as $r) {
        if (str_contains(manuscript_reference_html($r), '<a href=')) { $n++; }
    }
    return $n === 4;
})());

/* -------------------------------------------------------- the page itself */

section('The page renders the content');

check('index.php includes the content file',
    str_contains($index, "includes/manuscript.php"));
check('it loops the modules',    str_contains($index, 'MANUSCRIPT_MODULES'));
check('it loops the references', str_contains($index, 'MANUSCRIPT_REFERENCES'));

check('the mastery formula is shown, and matches helpers.php', (function () use ($index) {
    // The landing page states the recency decay; helpers.php is where it is
    // actually applied. If one changes, the other is now wrong.
    return str_contains($index, '0.15k')
        && abs(MASTERY_RECENCY_DECAY - 0.15) < 0.0001;
})());

check('the Bloom weights on the page match BLOOM_WEIGHTS', (function () use ($index) {
    foreach (BLOOM_WEIGHTS as $level => $weight) {
        // Each level and its weight appear in the table.
        if (!str_contains($index, '<td>' . $level . '</td>')) { return false; }
        if (!str_contains($index, '<td>' . number_format($weight, 1) . '</td>')) { return false; }
    }
    return true;
})());

check('the bands on the page match the mastery thresholds', (function () use ($index) {
    return str_contains($index, (string) (int) MASTERY_MASTERED_AT . '% and above')
        && str_contains($index, (string) (int) MASTERY_DEVELOPING_AT . '% to 84%')
        && str_contains($index, 'Under ' . MASTERY_MIN_ITEMS . ' scored answers');
})());

echo "\n=============================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
