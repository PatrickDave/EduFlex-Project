<?php
/**
 * EduFlex — subscription test suite.
 *
 *     php tests/subscription_test.php
 *
 * Runs against an in-memory database. No API key, no network, no server.
 *
 * Account and Access Management sub-module 5, "Manage Subscription", was listed
 * in Chapter III and not built: a row was written at registration and nothing
 * read it. These checks cover the two things that could go wrong now that
 * something does read it.
 *
 *   A learner must never be shown a bill. There is no payment in EduFlex, and
 *   the premium tier exists in the data dictionary rather than for sale. The
 *   suite asserts that nothing in the plan data offers a purchase.
 *
 *   The screen must not fall over on a missing or unknown row. Accounts created
 *   before the row was written, and rows carrying a plan name the system no
 *   longer defines, both have to render as the free plan rather than as an
 *   error, because the learner does in fact have every feature.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/subscription.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "  PASS  $label\n"; }
    else            { $failed++; echo "  FAIL  $label\n"; }
}
function section(string $name): void { echo "\n$name\n"; }

echo "EduFlex subscription suite\n==========================\n";

/* ----------------------------------------------------------- test database */

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("
CREATE TABLE subscription (
  subscription_id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL UNIQUE,
  plan_type TEXT NOT NULL,
  status TEXT NOT NULL,
  started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TEXT NULL);
");
db_set_connection($pdo);

/* ------------------------------------------------------------- the plans */

section('The plans EduFlex defines');

check('there is a free plan',    isset(SUBSCRIPTION_PLANS['free']));
check('there is a premium plan', isset(SUBSCRIPTION_PLANS['premium']));
check('the default is free',     SUBSCRIPTION_DEFAULT_PLAN === 'free');
check('the default plan is one that exists',
    isset(SUBSCRIPTION_PLANS[SUBSCRIPTION_DEFAULT_PLAN]));

check('free is available',       SUBSCRIPTION_PLANS['free']['available'] === true);
check('premium is NOT available', SUBSCRIPTION_PLANS['premium']['available'] === false);

check('every plan has a label, a summary and a feature list', (function () {
    foreach (SUBSCRIPTION_PLANS as $plan) {
        if (trim((string) $plan['label']) === '') { return false; }
        if (trim((string) $plan['summary']) === '') { return false; }
        if (!is_array($plan['includes']) || $plan['includes'] === []) { return false; }
    }
    return true;
})());

section('Nothing offers a purchase');

/* The point of the module as built. Keyword sniffing was the first attempt and
   it was wrong: it flagged the sentence saying nothing can be purchased, which
   is the denial, not an offer. These check the structure instead. */

check('no plan carries a price of any kind', (function () {
    foreach (SUBSCRIPTION_PLANS as $plan) {
        foreach (['price', 'cost', 'amount', 'currency', 'interval'] as $key) {
            if (array_key_exists($key, $plan)) { return false; }
        }
    }
    return true;
})());

check('no plan text contains a currency amount', (function () {
    // A figure attached to a currency marker is what a price looks like. Prose
    // about being free contains no such pair.
    $text = json_encode(SUBSCRIPTION_PLANS) ?: '';
    return !preg_match('/(?:[$]\s*\d|\b(?:PHP|USD|EUR)\s*\d)/i', $text);
})());

check('free is the only plan a learner can actually be given', (function () {
    foreach (SUBSCRIPTION_PLANS as $key => $plan) {
        if ($plan['available'] && $key !== 'free') { return false; }
    }
    return true;
})());

check('every unavailable plan says so in its own summary', (function () {
    foreach (SUBSCRIPTION_PLANS as $plan) {
        if ($plan['available']) { continue; }
        $summary = strtolower((string) $plan['summary']);
        if (!str_contains($summary, 'not offered') && !str_contains($summary, 'not available')) {
            return false;
        }
    }
    return true;
})());

check('the cost statement says EduFlex never asks for money',
    str_contains(strtolower(subscription_cost_statement()), 'never ask you for money'));
check('the cost statement says it is free',
    str_contains(strtolower(subscription_cost_statement()), 'free'));

check('the free plan includes the companion and mastery', (function () {
    $text = strtolower(implode(' ', SUBSCRIPTION_PLANS['free']['includes']));
    return str_contains($text, 'companion') && str_contains($text, 'mastery');
})());

check('the free plan is not artificially limited', (function () {
    // A "free" plan that withholds features would make the premium tier a real
    // paywall, which is exactly what this design avoids.
    $text = strtolower(implode(' ', SUBSCRIPTION_PLANS['free']['includes']));
    return str_contains($text, 'unlimited');
})());

/* ----------------------------------------------------- reading a real row */

section('Reading a learner with a row');

subscription_create(1);
$one = subscription_for(1);

check('the row was created',        $one['row_exists'] === true);
check('they are on the free plan',  $one['plan'] === 'free');
check('labelled Free',              $one['label'] === 'Free');
check('status is active',           $one['status'] === 'active');
check('a start date is recorded',   $one['started_at'] !== null);
check('no expiry is set',           $one['expires_at'] === null);
check('the plan is reported as available', $one['available'] === true);
check('the feature list comes through',    $one['includes'] !== []);

check('creating twice does not duplicate the row', (function () use ($pdo) {
    // user_id is UNIQUE, so the second insert fails. It must be swallowed.
    $second = subscription_create(1);
    $rows = (int) $pdo->query('SELECT COUNT(*) FROM subscription WHERE user_id = 1')
        ->fetchColumn();
    return $second === false && $rows === 1;
})());

/* -------------------------------------------------- the awkward cases */

section('A learner with no row at all');

/* Accounts created before the row was written, or one whose insert failed. The
   learner still has every feature, so the screen must say so. */
$none = subscription_for(999);

check('no row is reported honestly',   $none['row_exists'] === false);
check('but they are shown as free',    $none['plan'] === 'free');
check('with an active status',         $none['status'] === 'active');
check('and no start date invented',    $none['started_at'] === null);
check('and the full feature list',     $none['includes'] !== []);

section('A row with a plan the system no longer defines');

check('an unknown plan falls back to free rather than a blank screen',
    (function () use ($pdo) {
        $pdo->exec("INSERT INTO subscription (user_id, plan_type, status)
                    VALUES (2, 'platinum', 'active')");
        $odd = subscription_for(2);
        return $odd['plan'] === 'free' && $odd['label'] === 'Free' && $odd['includes'] !== [];
    })());

check('a premium row is read as premium, and still not available',
    (function () use ($pdo) {
        // Nothing in EduFlex can set this today, but the column allows it.
        $pdo->exec("INSERT INTO subscription (user_id, plan_type, status)
                    VALUES (3, 'premium', 'active')");
        $prem = subscription_for(3);
        return $prem['plan'] === 'premium' && $prem['available'] === false;
    })());

check('a missing table does not throw', (function () use ($pdo) {
    $pdo->exec('ALTER TABLE subscription RENAME TO subscription_hidden');
    $result = subscription_for(1);
    $pdo->exec('ALTER TABLE subscription_hidden RENAME TO subscription');
    return $result['plan'] === 'free' && $result['row_exists'] === false;
})());

check('creating into a missing table does not throw', (function () use ($pdo) {
    $pdo->exec('ALTER TABLE subscription RENAME TO subscription_hidden');
    $ok = subscription_create(77);
    $pdo->exec('ALTER TABLE subscription_hidden RENAME TO subscription');
    return $ok === false;
})());

/* ------------------------------------------------------------ presentation */

section('Presentation');

check('active gets the mastered band',  subscription_status_band('active') === 'mastered');
check('expired gets the weak band',     subscription_status_band('expired') === 'weak');
check('cancelled gets the neutral band', subscription_status_band('cancelled') === 'none');
check('every schema status has a band and a label', (function () {
    foreach (SUBSCRIPTION_STATUSES as $status) {
        if (!in_array(subscription_status_band($status),
            ['mastered', 'developing', 'weak', 'none'], true)) { return false; }
        if (subscription_status_label($status) === '') { return false; }
    }
    return true;
})());

check('the since line names the status and the date',
    subscription_since(['status' => 'active', 'started_at' => '2026-09-16 10:00:00'])
        === 'Active since 16 September 2026');
check('a missing date degrades to the status alone',
    subscription_since(['status' => 'active', 'started_at' => null]) === 'Active');
check('an unparseable date degrades to the status alone',
    subscription_since(['status' => 'active', 'started_at' => 'not a date']) === 'Active');
check('an empty date degrades to the status alone',
    subscription_since(['status' => 'cancelled', 'started_at' => '  ']) === 'Cancelled');

/* ------------------------------------------------------ the page and docs */

section('The Settings card and the consent documents');

$settings = (string) file_get_contents(__DIR__ . '/../app/settings.php');
$build    = (string) file_get_contents(__DIR__ . '/../build_pages.py');
$terms    = (string) file_get_contents(__DIR__ . '/../terms.php');

check('Settings renders the plan',       str_contains($settings, 'subscription_for('));
check('Settings includes the module',    str_contains($settings, 'includes/subscription.php'));
/* app/*.php is generated: a card present in the page but not in the template is
   deleted by the next build_pages.py run. */
check('the card comes from build_pages.py, not a hand edit',
    str_contains($build, 'subscription_for('));

/* Comments stripped first: terms.php records in a comment WHY the old sentence
   was removed, and a plain search finds that and reports a problem that is not
   there. php_strip_whitespace() uses PHP's own lexer. */
check('the Terms no longer claim there is no subscription',
    !str_contains(php_strip_whitespace(__DIR__ . '/../terms.php'), 'is no subscription'));
check('the Terms state the cost from one shared source',
    str_contains($terms, 'subscription_cost_statement()'));
check('the Terms say premium cannot be bought',
    str_contains($terms, 'cannot be bought'));

check('registration still creates the row through this module', (function () {
    $auth = (string) file_get_contents(__DIR__ . '/../includes/auth.php');
    return str_contains($auth, 'subscription_create($userId)')
        // and no longer writes the INSERT by hand
        && !str_contains($auth, "INSERT INTO subscription");
})());

echo "\n==========================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
