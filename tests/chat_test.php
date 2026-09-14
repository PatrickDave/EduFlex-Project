<?php
/**
 * EduFlex — companion chat test suite.
 *
 *     php tests/chat_test.php
 *
 * The grounding rules get the most attention. A companion that answers
 * confidently from outside the learner's material is the failure this whole
 * design exists to prevent, so the tests that prove it cannot are the ones to
 * show a panel.
 *
 * Runs against the mock provider and an in-memory database. No API key, no
 * network, no quota spent.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/chat.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "  PASS  $label\n"; }
    else            { $failed++; echo "  FAIL  $label\n"; }
}
function section(string $name): void { echo "\n$name\n"; }

echo "EduFlex companion chat suite\n============================\n";

/* ----------------------------------------------------------- test database */

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("
CREATE TABLE learning_resource (
  resource_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
  title TEXT NOT NULL, file_type TEXT NOT NULL DEFAULT 'pdf',
  storage_path TEXT NOT NULL DEFAULT 'x',
  processing_status TEXT NOT NULL DEFAULT 'processed');
CREATE TABLE resource_chunk (
  chunk_id INTEGER PRIMARY KEY AUTOINCREMENT, resource_id INTEGER NOT NULL,
  chunk_index INTEGER NOT NULL, content TEXT NOT NULL, word_count INTEGER NOT NULL DEFAULT 0);
CREATE TABLE ai_interaction (
  interaction_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
  resource_id INTEGER NULL, prompt TEXT NOT NULL, response TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
");
db_set_connection($pdo);

/* Two documents for learner 1, one for learner 2. The second learner exists
   only so every ownership guard has something to fail against. */
$pdo->exec("INSERT INTO learning_resource (user_id, title) VALUES (1, 'Signals Reviewer')");
$pdo->exec("INSERT INTO learning_resource (user_id, title) VALUES (1, 'Thermodynamics Notes')");
$pdo->exec("INSERT INTO learning_resource (user_id, title) VALUES (2, 'Someone Else Material')");

$chunk = static function (int $resourceId, int $index, string $text) use ($pdo): void {
    $stmt = $pdo->prepare('INSERT INTO resource_chunk
        (resource_id, chunk_index, content, word_count) VALUES (?,?,?,?)');
    $stmt->execute([$resourceId, $index, $text, str_word_count($text)]);
};

$chunk(1, 0, 'Introduction to the reviewer. This section sets out scope and notation.');
$chunk(1, 1, 'The Nyquist sampling theorem requires a sampling rate of at least twice '
           . 'the highest frequency in the signal. Aliasing occurs when this rate is not met.');
$chunk(1, 2, 'Harmonic content in a Fourier series describes how a periodic signal '
           . 'decomposes into sinusoids at integer multiples of the fundamental frequency.');
$chunk(2, 0, 'The first law of thermodynamics states that energy is conserved in a '
           . 'closed system. Entropy is introduced in the second law.');
$chunk(3, 0, 'Private material belonging to another learner, mentioning Nyquist and aliasing.');

/* ------------------------------------------------------------------ terms */

section('Reducing a question to searchable terms');

$terms = chat_query_terms('What is the Nyquist sampling theorem?');
check('content words are kept',      in_array('nyquist', $terms, true));
check('question words are dropped',  !in_array('what', $terms, true));
check('articles are dropped',        !in_array('the', $terms, true));
check('short words are dropped',     !in_array('is', $terms, true));

$plural = chat_query_terms('explain harmonics');
check('a plural also matches its singular',
    in_array('harmonics', $plural, true) && in_array('harmonic', $plural, true));
check('"explain" is not a search term', !in_array('explain', $plural, true));

check('a question of pure filler yields no terms',
    chat_query_terms('can you explain this to me please') === []);
check('the same word twice is searched once',
    count(chat_query_terms('entropy entropy entropy')) === 1);

/* -------------------------------------------------------------- retrieval */

section('Finding the right passage');

$ctx = chat_select_context(1, 'What does the Nyquist theorem say about sampling rate?');
check('passages are returned',       $ctx['passages'] !== []);
check('the material is searchable',  $ctx['searchable'] === true);
check('retrieval is confident',      $ctx['confident'] === true);
check('the best passage is the Nyquist one',
    str_contains($ctx['passages'][0]['content'], 'Nyquist sampling theorem'));
check('the passage carries its document title',
    $ctx['passages'][0]['title'] === 'Signals Reviewer');

$ctx2 = chat_select_context(1, 'What is entropy?');
check('a question about the second document finds it',
    str_contains($ctx2['passages'][0]['content'], 'Entropy'));
check('retrieval crosses documents, not just the first one',
    $ctx2['passages'][0]['resource_id'] === 2);

$scoped = chat_select_context(1, 'What is entropy?', 1);
check('scoping to one document excludes the other',
    array_unique(array_column($scoped['passages'], 'resource_id')) === [1]);

$other = chat_select_context(2, 'Nyquist aliasing');
check('a learner only ever searches their own material',
    count($other['passages']) === 1
    && $other['passages'][0]['resource_id'] === 3);

$none = chat_select_context(99, 'anything at all');
check('a learner with no material is not searchable',
    $none['searchable'] === false && $none['passages'] === []);

/* ------------------------------------------------------- the honesty flag */

section('Knowing when the material does not cover the question');

$off = chat_select_context(1, 'Explain the causes of the French Revolution');
check('an off-topic question still returns passages for context',
    $off['passages'] !== []);
check('but it is marked as not confident', $off['confident'] === false);

$filler = chat_select_context(1, 'Can you summarise this for me?');
check('a question with no content words is not treated as off-topic',
    $filler['confident'] === true);
check('and it falls back to the opening passages in document order',
    $filler['passages'][0]['chunk_index'] === 0);

/* ---------------------------------------------------------------- prompts */

section('The prompt sent to the provider');

$prompt = chat_build_prompt($ctx['passages'], [], 'What is aliasing?', true);
check('the passages are delimited',     str_contains($prompt, '<passages>'));
check('each passage is numbered',       str_contains($prompt, '<passage id="1"'));
check('each passage names its source',  str_contains($prompt, 'source="Signals Reviewer"'));
check('the question is included',       str_contains($prompt, 'What is aliasing?'));
check('a confident search adds no warning',
    !str_contains($prompt, 'found no passage matching'));

$warned = chat_build_prompt($off['passages'], [], 'French Revolution?', false);
check('an unmatched search warns the model explicitly',
    str_contains($warned, 'found no passage matching'));
check('and tells it to say the material does not cover it',
    str_contains($warned, 'does not cover it'));

$withHistory = chat_build_prompt($ctx['passages'], [
    ['question' => 'What is sampling?', 'answer' => 'Measuring a signal at intervals.'],
], 'Why does it matter?', true);
check('previous turns are replayed so follow-ups work',
    str_contains($withHistory, 'What is sampling?')
    && str_contains($withHistory, 'Measuring a signal at intervals.'));

$system = chat_system_prompt();
check('the system prompt forbids outside knowledge',
    str_contains($system, 'ONLY from the passages'));
check('it makes an honest refusal an acceptable answer',
    str_contains($system, 'not a failure'));
check('it requires citations',   str_contains($system, 'Cite the passages'));
check('it refuses to do graded work',
    str_contains($system, 'graded work'));

/* --------------------------------------------------------- reading replies */

section('Reading the reply');

$parsed = chat_parse_reply(
    ['answer' => 'Sampling must be twice the highest frequency.',
     'used_passages' => [1, 2], 'grounded' => true],
    '{}',
    $ctx['passages']
);
check('the answer is read',       $parsed['answer'] === 'Sampling must be twice the highest frequency.');
check('citations become sources', count($parsed['sources']) === 2);
check('a source names its document',
    $parsed['sources'][0]['title'] === 'Signals Reviewer');
check('grounded is carried through', $parsed['grounded'] === true);

$ungrounded = chat_parse_reply(
    ['answer' => 'Your material does not cover that.', 'used_passages' => [], 'grounded' => false],
    '{}', $ctx['passages']
);
check('an ungrounded answer is flagged', $ungrounded['grounded'] === false);
check('and cites nothing',               $ungrounded['sources'] === []);

$invented = chat_parse_reply(
    ['answer' => 'x', 'used_passages' => [1, 99], 'grounded' => true],
    '{}', $ctx['passages']
);
check('a citation to a passage that was never sent is discarded',
    count($invented['sources']) === 1);

$repeated = chat_parse_reply(
    ['answer' => 'x', 'used_passages' => [1, 1, 1], 'grounded' => true],
    '{}', $ctx['passages']
);
check('the same source is listed once', count($repeated['sources']) === 1);

$plain = chat_parse_reply(null, 'A provider that ignored the JSON instruction.', $ctx['passages']);
check('a non-JSON reply is still shown to the learner',
    $plain['answer'] === 'A provider that ignored the JSON instruction.');

$empty = chat_parse_reply(['answer' => '   '], '', $ctx['passages']);
check('an empty answer becomes a readable message',
    str_contains($empty['answer'], 'could not produce an answer'));
check('and is not presented as grounded', $empty['grounded'] === false);

$stringIds = chat_parse_reply(
    ['answer' => 'x', 'used_passages' => ['1', '2'], 'grounded' => true],
    '{}', $ctx['passages']
);
check('passage ids sent as strings still resolve', count($stringIds['sources']) === 2);

/* ------------------------------------------------------------ asking, end to end */

section('Asking a question end to end');

ai_provider(new MockProvider());

$ask = chat_ask(1, 'What does the Nyquist theorem require?');
check('the question is answered',        $ask['ok'] === true);
check('an answer comes back',            is_string($ask['answer']) && $ask['answer'] !== '');
check('the answer is grounded',          $ask['grounded'] === true);
check('sources are attached',            $ask['sources'] !== []);
check('the exchange is logged',
    (int) $pdo->query("SELECT COUNT(*) FROM ai_interaction WHERE prompt LIKE 'chat:%'")
        ->fetchColumn() === 1);

$offTopic = chat_ask(1, 'Who won the 1998 World Cup final?');
check('an off-material question is answered honestly',
    $offTopic['ok'] === true && $offTopic['grounded'] === false);
check('and cites nothing',  $offTopic['sources'] === []);
check('and says so in words',
    str_contains(mb_strtolower((string) $offTopic['answer']), 'does not'));

check('an empty question is refused before any call',
    chat_ask(1, '   ')['ok'] === false);
check('an over-long question is refused',
    chat_ask(1, str_repeat('a', CHAT_MAX_QUESTION + 1))['ok'] === false);

check('a learner with no material is told to upload first', (function () {
    $r = chat_ask(99, 'What is anything?');
    return $r['ok'] === false && str_contains((string) $r['error'], 'Upload a document');
})());

check('scoping to another learner\'s material is refused', (function () {
    $r = chat_ask(1, 'What is this?', 3);
    return $r['ok'] === false && str_contains((string) $r['error'], 'not found');
})());

check('a provider failure is reported, not swallowed', (function () use ($pdo) {
    $mock = new MockProvider();
    $mock->queue(AiResult::failure('Rate limited by the provider.'));
    ai_provider($mock);
    $r = chat_ask(1, 'What is aliasing?');
    ai_provider(new MockProvider());
    return $r['ok'] === false && str_contains((string) $r['error'], 'Rate limited');
})());

/* -------------------------------------------------------------- history */

section('Conversation history');

$history = chat_history(1);
check('previous turns are available',  count($history) > 0);
check('history is oldest first',
    str_contains($history[0]['question'], 'Nyquist'));
check('the stored answer is unwrapped from its JSON',
    !str_starts_with($history[0]['answer'], '{'));
check('a failed call is not replayed as an answer',
    !array_filter($history, static fn($h) => str_starts_with($h['answer'], '[failed:')));

check('history is capped by a character budget', (function () use ($pdo) {
    $long = str_repeat('This is a very long stored answer. ', 300);
    for ($i = 0; $i < 6; $i++) {
        $stmt = $pdo->prepare('INSERT INTO ai_interaction (user_id, prompt, response)
                               VALUES (1, ?, ?)');
        $stmt->execute(['chat:question ' . $i, json_encode(['answer' => $long])]);
    }
    $h = chat_history(1);
    $chars = 0;
    foreach ($h as $turn) {
        $chars += mb_strlen($turn['question']) + mb_strlen($turn['answer']);
    }
    return $chars <= CHAT_HISTORY_BUDGET;
})());

check('one learner never sees another learner\'s conversation', (function () use ($pdo) {
    $pdo->exec("INSERT INTO ai_interaction (user_id, prompt, response)
                VALUES (2, 'chat:a private question', '{\"answer\":\"private\"}')");
    foreach (chat_history(1, 50) as $turn) {
        if (str_contains($turn['question'], 'private')) {
            return false;
        }
    }
    return true;
})());

$thread = chat_thread(1);
check('the thread renders oldest first',
    str_contains($thread[0]['question'], 'Nyquist'));
check('the thread excludes other learners',
    !array_filter($thread, static fn($t) => str_contains($t['question'], 'private')));

check('detection and generation calls are not shown as chat', (function () use ($pdo) {
    $pdo->exec("INSERT INTO ai_interaction (user_id, prompt, response)
                VALUES (1, 'Topic: detect these', '{\"topics\":[]}')");
    foreach (chat_thread(1, 50) as $turn) {
        if (str_contains($turn['question'], 'detect these')) {
            return false;
        }
    }
    return true;
})());

check('clearing removes the learner\'s conversation and nothing else',
    chat_clear(1) === true
    && (int) $pdo->query("SELECT COUNT(*) FROM ai_interaction
                           WHERE user_id = 1 AND prompt LIKE 'chat:%'")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM ai_interaction
                           WHERE user_id = 2 AND prompt LIKE 'chat:%'")->fetchColumn() === 1
    && (int) $pdo->query("SELECT COUNT(*) FROM ai_interaction
                           WHERE user_id = 1 AND prompt NOT LIKE 'chat:%'")->fetchColumn() === 1);

echo "\n============================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
