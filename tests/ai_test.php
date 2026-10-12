<?php
/**
 * EduFlex — AI layer and topic detection test suite.
 *
 *     php tests/ai_test.php
 *
 * Runs entirely against the mock provider and an in-memory database. No API
 * key, no network, no quota spent.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/topics.php';
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

echo "EduFlex AI and topic detection suite\n====================================\n";

/* ----------------------------------------------------------- test database */

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("
CREATE TABLE learning_resource (
  resource_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
  title TEXT NOT NULL, file_type TEXT NOT NULL, storage_path TEXT NOT NULL,
  processing_status TEXT NOT NULL DEFAULT 'pending');
CREATE TABLE resource_chunk (
  chunk_id INTEGER PRIMARY KEY AUTOINCREMENT, resource_id INTEGER NOT NULL,
  chunk_index INTEGER NOT NULL, content TEXT NOT NULL, word_count INTEGER NOT NULL);
CREATE TABLE topic_progress (
  topic_progress_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
  resource_id INTEGER NOT NULL, topic_name TEXT NOT NULL,
  mastery_score REAL NOT NULL DEFAULT 0, weakness_priority TEXT NOT NULL DEFAULT 'none',
  scored_items INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE ai_interaction (
  interaction_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
  resource_id INTEGER NULL, prompt TEXT NOT NULL, response TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
");
db_set_connection($pdo);

$pdo->exec("INSERT INTO learning_resource (user_id, title, file_type, storage_path, processing_status)
            VALUES (1, 'Signals Reviewer', 'pdf', 'x', 'processed')");
foreach ([
    'Fourier Series decomposes periodic signals into sinusoids. Harmonic content matters.',
    'Nyquist Sampling requires twice the highest frequency. Aliasing appears otherwise.',
    'Fourier Series again, restated in a later section with more Harmonic detail.',
] as $i => $text) {
    $stmt = $pdo->prepare('INSERT INTO resource_chunk (resource_id, chunk_index, content, word_count) VALUES (1,?,?,?)');
    $stmt->execute([$i, $text, str_word_count($text)]);
}

/* --------------------------------------------------------------- sampling */

section('Chunk sampling (the quota guard)');

$mk = static fn(int $n): array => array_map(
    static fn($i) => ['chunk_index' => $i, 'content' => "chunk $i"], range(0, $n - 1));

check('a short document is covered completely',
    count(topics_select_chunks($mk(5), 12)) === 5);
check('a long document is capped',
    count(topics_select_chunks($mk(205), 12)) === 12);
check('205 chunks cost 12 calls, not 205',
    count(topics_select_chunks($mk(205), 12)) < 205);

$spread = topics_select_chunks($mk(205), 12);
$first  = (int) $spread[0]['chunk_index'];
$last   = (int) $spread[count($spread) - 1]['chunk_index'];
check('the sample starts at the beginning', $first === 0);
check('the sample reaches the far end of the document', $last > 150);

$indexes = array_column($spread, 'chunk_index');
check('no chunk is sampled twice', count($indexes) === count(array_unique($indexes)));
check('the sample stays in order', $indexes === array_values($indexes));
check('an empty document samples nothing', topics_select_chunks([], 12) === []);

/* ------------------------------------------------------------ normalising */

section('Topic name merging');

check('case does not create a duplicate',
    topics_normalise_key('Fourier Series') === topics_normalise_key('fourier series'));
check('punctuation does not create a duplicate',
    topics_normalise_key('Fourier-Series') === topics_normalise_key('Fourier Series'));
check('a leading article is ignored',
    topics_normalise_key('The Chain Rule') === topics_normalise_key('Chain Rule'));
check('different topics stay different',
    topics_normalise_key('Fourier Series') !== topics_normalise_key('Nyquist Sampling'));
check('trailing punctuation is trimmed from the name',
    topics_clean_name('  Fourier Series.  ') === 'Fourier Series');

/* --------------------------------------------------------- reply handling */

section('Parsing a model reply');

$good = topics_parse_reply(['topics' => [
    ['topic' => 'Fourier Series', 'summary' => 'Decomposition of signals.'],
    ['topic' => 'Nyquist Sampling', 'summary' => 'Sampling rate limits.'],
]]);
check('reads a well-formed reply', count($good) === 2);
check('keeps the topic name', $good[0]['topic'] === 'Fourier Series');
check('keeps the summary', str_contains($good[0]['summary'], 'Decomposition'));

check('accepts a bare array without the topics key',
    count(topics_parse_reply([['topic' => 'Aliasing']])) === 1);
check('accepts a plain list of strings',
    count(topics_parse_reply(['topics' => ['Aliasing', 'Harmonics']])) === 2);

check('discards an entry with no name',
    topics_parse_reply(['topics' => [['summary' => 'orphan']]]) === []);
check('discards a name that is too short',
    topics_parse_reply(['topics' => [['topic' => 'ab']]]) === []);
check('discards a sentence in the topic field',
    topics_parse_reply(['topics' => [['topic' =>
        'This section explains how the Fourier series decomposes a periodic signal into parts']]]) === []);
check('survives null', topics_parse_reply(null) === []);
check('survives the wrong shape entirely',
    topics_parse_reply(['topics' => 'not an array']) === []);
check('an empty topics array is valid and empty',
    topics_parse_reply(['topics' => []]) === []);

/* ------------------------------------------------------- JSON extraction */

section('Reading JSON the model wrapped badly');

final class JsonProbe extends HttpAiProvider {
    public function name(): string { return 'probe'; }
    public function complete(string $s, string $u, bool $j = false, int $m = 1500): AiResult {
        return AiResult::failure('unused');
    }
    public function tryDecode(string $t): ?array { return $this->decodeJson($t); }
}
$probe = new JsonProbe();

check('plain JSON decodes',
    $probe->tryDecode('{"topics":[]}') !== null);
check('a markdown fence is stripped',
    $probe->tryDecode("```json\n{\"topics\":[{\"topic\":\"X\"}]}\n```") !== null);
check('an unlabelled fence is stripped',
    $probe->tryDecode("```\n{\"topics\":[]}\n```") !== null);
check('a chatty preamble is skipped',
    $probe->tryDecode('Here are the topics I found: {"topics":[{"topic":"Y"}]}') !== null);
check('unparseable text returns null',
    $probe->tryDecode('I could not find any topics in this excerpt.') === null);

/* --------------------------------------------------------- mock provider */

section('Mock provider');

$mock = new MockProvider();
$r = $mock->complete('sys', 'Fourier Series and Nyquist Sampling appear here.', true);
check('returns success', $r->ok === true);
check('returns decoded data', is_array($r->data));
check('derives topics from the text sent',
    isset($r->data['topics']) && count($r->data['topics']) > 0);
check('reports itself as mock', $mock->name() === 'mock');

$mock->queue(AiResult::failure('Rate limited.'));
check('a queued failure is handed back',
    $mock->complete('s', 'u', true)->ok === false);

/* The mock draws distractors from the passage. A short passage gives it very
   few sentences to work with, and it used to reuse one inside a single
   question, which the validator then rejected as duplicate options. Every set
   generated in demo mode came back empty. */
$shortPrompt = "Topic: Ohm's Law\nNumber of questions: 6\n"
    . "<excerpt>Voltage equals current times resistance. "
    . "Resistance opposes the flow of charge.</excerpt>";
// The real system prompt, so the mock takes its question branch rather than
// its topic branch, exactly as generation does.
$short = (new MockProvider())->complete(
    questions_system_prompt(), $shortPrompt, true);

$allDistinct = true;
$allFour     = true;
$answerInSet = true;
foreach ($short->data['questions'] ?? [] as $q) {
    $opts = $q['options'] ?? [];
    if (count($opts) !== 4)                    { $allFour = false; }
    if (count(array_unique($opts)) !== count($opts)) { $allDistinct = false; }
    if (!in_array($q['answer'] ?? null, $opts, true)) { $answerInSet = false; }
}

check('a two-sentence passage still yields questions',
    count($short->data['questions'] ?? []) === 6);
check('every mock question has four options', $allFour);
check('no mock question repeats an option', $allDistinct);
check('the mock answer is always one of the options', $answerInSet);

$valid = questions_validate($short->data, 'Remember');
check('the validator accepts every mock question', count($valid['items']) === 6);
check('the validator rejects none of them', $valid['rejected'] === 0);

/* --------------------------------------------------- detection end to end */

section('Topic detection against the database');

ai_provider(new MockProvider());
$result = topics_detect(1, 1);

check('detection succeeds',            $result['ok'] === true);
check('it made one call per chunk',    $result['calls'] === 3);
check('no call failed',                $result['failed'] === 0);
check('it found topics',               $result['topics'] > 0);
check('rows were inserted',            $result['new'] === $result['topics']);

$stored = $pdo->query('SELECT topic_name FROM topic_progress WHERE resource_id = 1')->fetchAll();
check('topics reached the database',   count($stored) === $result['topics']);

$names = array_column($stored, 'topic_name');
$keys  = array_map('topics_normalise_key', $names);
check('no duplicate topic was stored', count($keys) === count(array_unique($keys)));

check('new topics start at zero mastery',
    (float) $pdo->query('SELECT MAX(mastery_score) FROM topic_progress')->fetchColumn() === 0.0);
check('every call was logged',
    (int) $pdo->query('SELECT COUNT(*) FROM ai_interaction')->fetchColumn() === 3);

section('Re-running detection');

// A learner has made progress on one topic. Re-running must not erase it.
$pdo->exec("UPDATE topic_progress SET mastery_score = 82, scored_items = 9
             WHERE topic_progress_id = 1");
$before = (int) $pdo->query('SELECT COUNT(*) FROM topic_progress')->fetchColumn();

$again = topics_detect(1, 1);
$after = (int) $pdo->query('SELECT COUNT(*) FROM topic_progress')->fetchColumn();

check('re-running adds no duplicates',  $after === $before);
check('it reports nothing new',         $again['new'] === 0);
check('recorded mastery is preserved',
    (float) $pdo->query('SELECT mastery_score FROM topic_progress WHERE topic_progress_id = 1')
        ->fetchColumn() === 82.0);
check('scored items are preserved',
    (int) $pdo->query('SELECT scored_items FROM topic_progress WHERE topic_progress_id = 1')
        ->fetchColumn() === 9);

/* --------------------------------------------------------- failure paths */

section('When the provider fails');

$dead = new MockProvider();
for ($i = 0; $i < 5; $i++) {
    $dead->queue(AiResult::failure('The provider rate limit was reached.'));
}
ai_provider($dead);

$bad = topics_detect(1, 1);
check('detection reports failure',      $bad['ok'] === false);
check('the provider reason is passed through',
    is_string($bad['error']) && str_contains($bad['error'], 'rate limit'));
check('no topics were invented',        $bad['topics'] === 0);

section('A 429 that is not a rate limit');

/* 12 October 2026. Patrick's first real key authenticated perfectly and then
   reported "The provider rate limit was reached. Tried 3 times." It was not
   throttled. OpenAI returns 429 with type "insufficient_quota" when the account
   has no credit, and the driver was treating every 429 the same: three round
   trips, then a message pointing at the rate tier instead of the billing page.

   Only the response body separates the two, so these assert the body is read.
   Getting this wrong costs retries during a defense and sends you to the wrong
   settings screen. */

check('the real OpenAI no-credit body is recognised',
    ai_body_is_out_of_credit('{"error":{"message":"You have no credits remaining. '
        . 'Add credits to continue using the API at https://platform.openai.com/'
        . 'settings/organization/billing/.","type":"insufficient_quota",'
        . '"param":null,"code":"credit_balance_exhausted"}}'));

check('the older billing_hard_limit_reached spelling is recognised',
    ai_body_is_out_of_credit('{"error":{"type":"insufficient_quota",'
        . '"code":"billing_hard_limit_reached"}}'));

/* This assertion used to run the other way, and that was the defect. The
   sentence "you exceeded your current quota" is what BOTH a dead OpenAI account
   and a throttled Gemini free tier say, so on its own it decides nothing. A
   provider that means billing always says so in a structured field. */
check('the quota sentence alone is NOT enough to call it a billing problem',
    !ai_body_is_out_of_credit('{"error":{"message":"You exceeded your current quota."}}'));

check('a genuine rate limit is NOT mistaken for no credit',
    !ai_body_is_out_of_credit('{"error":{"message":"Rate limit reached for gpt-4o-mini '
        . 'in organization org-x on requests per min.","type":"requests",'
        . '"code":"rate_limit_exceeded"}}'));

check('a rate-limit message that happens to say "quota" is not caught',
    !ai_body_is_out_of_credit('{"error":{"message":"Request too large. Your quota '
        . 'allows 30000 tokens per minute.","code":"rate_limit_exceeded"}}'));

/* The regression this file exists to prevent, captured verbatim from Google on
   12 October 2026. Gemini's free-tier THROTTLE tells you to check your billing,
   so any matcher that reads the prose reports a dead account while the very
   next call succeeds. That is precisely what happened to Patrick: the companion
   said "no credit left" with interaction #41 sitting in ai_interaction having
   returned a full set of questions a minute earlier. */
$geminiThrottle = '{"error":{"code":429,"message":"You exceeded your current quota, '
    . 'please check your plan and billing details. For more information on this error, '
    . 'head to: https://ai.google.dev/gemini-api/docs/rate-limits. '
    . '\n* Quota exceeded for metric: generativelanguage.googleapis.com/'
    . 'generate_content_free_tier_requests, limit: 20, model: gemini-3.6-flash'
    . 'Please retry in 8h45m30.388394773s.","status":"RESOURCE_EXHAUSTED",'
    . '"details":[{"@type":"type.googleapis.com/google.rpc.QuotaFailure",'
    . '"violations":[{"quotaId":"GenerateRequestsPerDayPerProjectPerModel-FreeTier",'
    . '"quotaValue":"20"}]},{"@type":"type.googleapis.com/google.rpc.RetryInfo",'
    . '"retryDelay":"31530s"}]}}';

check('Gemini free-tier throttling is NOT reported as a billing problem',
    !ai_body_is_out_of_credit($geminiThrottle));

check('RESOURCE_EXHAUSTED alone is enough to rule out a billing problem',
    !ai_body_is_out_of_credit('{"error":{"status":"RESOURCE_EXHAUSTED",'
        . '"message":"check your plan and billing details"}}'));

section('Reading how long to wait');

check('the retry delay is read out of the RetryInfo detail',
    ai_retry_after_seconds($geminiThrottle) === 31530);

check('a body with no RetryInfo returns null',
    ai_retry_after_seconds('{"error":{"code":429,"message":"slow down"}}') === null);

check('a body that is not JSON returns null',
    ai_retry_after_seconds('<html>502</html>') === null);

check('fractional seconds are rounded, not rejected',
    ai_retry_after_seconds('{"error":{"details":[{"retryDelay":"12.7s"}]}}') === 13);

check('hours and minutes read the way a person would say them',
    ai_format_duration(31530) === '8h 45m');
check('a whole number of hours drops the minutes',
    ai_format_duration(7200) === '2h');
check('under an hour is minutes',   ai_format_duration(300) === '5m');
check('under a minute is seconds',  ai_format_duration(45) === '45s');

check('an empty body is not treated as no credit',
    !ai_body_is_out_of_credit(''));

check('a body that is not JSON at all does not crash it',
    !ai_body_is_out_of_credit('<html><body>502 Bad Gateway</body></html>'));

check('a success body is not treated as no credit',
    !ai_body_is_out_of_credit('{"choices":[{"message":{"content":"hello"}}]}'));

section('Gemini must not be left thinking');

/* 12 October 2026. Current Gemini Flash models reason before answering, and the
   reasoning comes out of maxOutputTokens. Measured on gemini-3.5-flash asked for
   three short JSON objects in a 300-token cap: 284 thought tokens, 12 output
   tokens, finishReason MAX_TOKENS, and a reply truncated to `{"ok":`.

   EduFlex caps output hard on purpose: 900 tokens for topics and chat, 2600 for
   a set of 8 questions. With thinking left on, generation returns half a JSON
   document, questions_validate() rejects the set as malformed, and the Chapter
   IV rejection rate ends up measuring the token cap rather than the model.

   This is a source-level check rather than a live one, because the suite must
   keep working with no key, no network and no quota. It cannot prove the
   provider honours the field; tools/ai_ping.php does that in one real call.
   What it does prove is that nobody has quietly deleted it. */

$aiSource = (string) file_get_contents(__DIR__ . '/../includes/ai.php');

check('the Gemini driver sends a thinking budget', (function () use ($aiSource) {
    $gemini = (string) (strstr($aiSource, 'class GeminiProvider') ?: '');
    return str_contains($gemini, 'thinkingConfig');
})());

check('and that budget is zero', (function () use ($aiSource) {
    $gemini = (string) (strstr($aiSource, 'class GeminiProvider') ?: '');
    return (bool) preg_match("/'thinkingBudget'\s*=>\s*0\b/", $gemini);
})());

check('it is set for every call, not only the JSON ones', (function () use ($aiSource) {
    // A budget applied only inside "if ($wantJson)" would leave chat and any
    // future plain-text call still burning its cap on reasoning.
    $gemini = (string) (strstr($aiSource, 'class GeminiProvider') ?: '');
    $budgetAt = strpos($gemini, 'thinkingBudget');
    $jsonAt   = strpos($gemini, 'if ($wantJson)');
    return $budgetAt !== false && $jsonAt !== false && $budgetAt < $jsonAt;
})());

// CHAT_CONTEXT_CHUNKS lives in chat.php, which this suite does not load.
check('the cap it protects is still the one that was measured against',
    QUESTIONS_PER_SET === 8);

section('Guard clauses');

ai_provider(new MockProvider());
check('an unknown resource is rejected',        topics_detect(999, 1)['ok'] === false);
check('another learner cannot read it',         topics_detect(1, 2)['ok'] === false);

$pdo->exec("INSERT INTO learning_resource (user_id, title, file_type, storage_path, processing_status)
            VALUES (1, 'Not read yet', 'pdf', 'y', 'pending')");
$r = topics_detect(2, 1);
check('an unprocessed resource is rejected',    $r['ok'] === false);
check('and it says why',
    is_string($r['error']) && str_contains($r['error'], 'not been read'));

$pdo->exec("INSERT INTO learning_resource (user_id, title, file_type, storage_path, processing_status)
            VALUES (1, 'No chunks', 'pdf', 'z', 'processed')");
check('a resource with no stored text is rejected', topics_detect(3, 1)['ok'] === false);

/* -------------------------------------------------------------- counting */

section('Counting');

check('topic count matches the rows',
    topics_count_for_resource(1, 1) === $after);
check('a resource with no topics counts zero',
    topics_count_for_resource(3, 1) === 0);

echo "\n====================================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
