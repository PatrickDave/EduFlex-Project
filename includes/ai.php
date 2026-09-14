<?php
/**
 * EduFlex — language model access.
 *
 * Every call to a model in this system goes through AiProvider. Nothing else
 * in the codebase knows which provider is in use, so switching is a one-line
 * change in config/ai.php rather than a rewrite.
 *
 * Three drivers ship:
 *   OpenAiCompatibleProvider  OpenAI and the many hosts that copy its shape
 *   GeminiProvider            Google's Generative Language API
 *   MockProvider              no network, no key, no cost; used by the tests
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/ai.php';
require_once __DIR__ . '/../config/database.php';

/**
 * The outcome of one model call. Always returned, never thrown, so callers
 * handle a failed generation the same way they handle any other bad input.
 */
final class AiResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $text = '',
        public readonly ?array $data = null,     // decoded JSON when asked for
        public readonly ?string $error = null,
        public readonly int $latencyMs = 0,
        public readonly int $attempts = 1,
        public readonly string $model = '',
    ) {
    }

    public static function failure(string $error, int $latencyMs = 0, int $attempts = 1): self
    {
        return new self(false, '', null, $error, $latencyMs, $attempts);
    }
}

interface AiProvider
{
    public function name(): string;

    /**
     * @param bool $wantJson ask the provider to return strict JSON
     */
    public function complete(
        string $systemPrompt,
        string $userPrompt,
        bool $wantJson = false,
        int $maxTokens = 1500
    ): AiResult;
}

/* -------------------------------------------------------------------------
   Shared HTTP behaviour
   ------------------------------------------------------------------------- */

abstract class HttpAiProvider implements AiProvider
{
    /**
     * POST JSON, retrying on the failures that are worth retrying.
     *
     * A 429 means the free tier's rate limit was hit; waiting and trying again
     * is the correct response, not an error to show the learner. A 5xx is the
     * provider having a bad moment. A 4xx other than 429 is our mistake and
     * retrying would just repeat it.
     *
     * @return array{ok:bool, status:int, body:string, error:?string, attempts:int, latencyMs:int}
     */
    protected function post(string $url, array $payload, array $headers): array
    {
        $started  = microtime(true);
        $attempt  = 0;
        $lastErr  = 'Request failed.';
        $lastCode = 0;

        while ($attempt < AI_MAX_RETRIES) {
            $attempt++;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => AI_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
                CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);

            $body   = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlEr = curl_error($ch);
            curl_close($ch);

            $latency = (int) round((microtime(true) - $started) * 1000);

            if ($body === false) {
                $lastErr  = 'Could not reach the AI provider. ' . $curlEr;
                $lastCode = 0;
            } elseif ($status >= 200 && $status < 300) {
                return ['ok' => true, 'status' => $status, 'body' => (string) $body,
                        'error' => null, 'attempts' => $attempt, 'latencyMs' => $latency];
            } elseif ($status === 401 || $status === 403) {
                // Never retry an auth failure; the key is wrong and will stay wrong.
                return ['ok' => false, 'status' => $status, 'body' => (string) $body,
                        'error' => 'The API key was rejected. Check AI_API_KEY in config/ai.php.',
                        'attempts' => $attempt, 'latencyMs' => $latency];
            } elseif ($status === 429 || $status >= 500) {
                $lastErr  = $status === 429
                    ? 'The provider rate limit was reached.'
                    : 'The provider returned an error (' . $status . ').';
                $lastCode = $status;
            } else {
                return ['ok' => false, 'status' => $status, 'body' => (string) $body,
                        'error' => 'The provider rejected the request (' . $status . ').',
                        'attempts' => $attempt, 'latencyMs' => $latency];
            }

            if ($attempt < AI_MAX_RETRIES) {
                // Exponential backoff: 1.2s, 2.4s, 4.8s.
                usleep(AI_RETRY_BASE_MS * 1000 * (2 ** ($attempt - 1)));
            }
        }

        return ['ok' => false, 'status' => $lastCode, 'body' => '',
                'error' => $lastErr . ' Tried ' . $attempt . ' times.',
                'attempts' => $attempt,
                'latencyMs' => (int) round((microtime(true) - $started) * 1000)];
    }

    /**
     * Models sometimes wrap JSON in a markdown fence despite being asked not
     * to. Strip it before decoding rather than failing the whole generation.
     *
     * @return array<mixed>|null
     */
    protected function decodeJson(string $text): ?array
    {
        $text = trim($text);

        if (str_starts_with($text, '```')) {
            $text = (string) preg_replace('/^```[a-zA-Z]*\s*/', '', $text);
            $text = (string) preg_replace('/\s*```$/', '', $text);
            $text = trim($text);
        }

        // Some models add a sentence before the JSON. Take the outermost object
        // or array if the whole string does not parse.
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strcspn($text, '{[');
        if ($start < strlen($text)) {
            $open  = $text[$start];
            $close = $open === '{' ? '}' : ']';
            $end   = strrpos($text, $close);
            if ($end !== false && $end > $start) {
                $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }
}

/* -------------------------------------------------------------------------
   OpenAI-compatible
   ------------------------------------------------------------------------- */

final class OpenAiCompatibleProvider extends HttpAiProvider
{
    public function name(): string
    {
        return 'openai-compatible:' . AI_MODEL;
    }

    public function complete(
        string $systemPrompt,
        string $userPrompt,
        bool $wantJson = false,
        int $maxTokens = 1500
    ): AiResult {
        if (AI_API_KEY === '') {
            return AiResult::failure('No API key is set. Add it to config/ai.php.');
        }

        $payload = [
            'model'    => AI_MODEL,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
            'max_tokens'  => $maxTokens,
            // Low temperature: this is extraction, not creative writing. The
            // same document should give the same topics twice running.
            'temperature' => 0.2,
        ];

        if ($wantJson) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $res = $this->post(
            rtrim(AI_BASE_URL, '/') . '/chat/completions',
            $payload,
            ['Authorization: Bearer ' . AI_API_KEY]
        );

        if (!$res['ok']) {
            return AiResult::failure((string) $res['error'], $res['latencyMs'], $res['attempts']);
        }

        $json = json_decode($res['body'], true);
        $text = $json['choices'][0]['message']['content'] ?? null;

        if (!is_string($text) || trim($text) === '') {
            return AiResult::failure(
                'The provider returned no content.', $res['latencyMs'], $res['attempts']
            );
        }

        return new AiResult(
            true,
            $text,
            $wantJson ? $this->decodeJson($text) : null,
            null,
            $res['latencyMs'],
            $res['attempts'],
            AI_MODEL
        );
    }
}

/* -------------------------------------------------------------------------
   Google Gemini
   ------------------------------------------------------------------------- */

final class GeminiProvider extends HttpAiProvider
{
    public function name(): string
    {
        return 'gemini:' . AI_MODEL;
    }

    public function complete(
        string $systemPrompt,
        string $userPrompt,
        bool $wantJson = false,
        int $maxTokens = 1500
    ): AiResult {
        if (AI_API_KEY === '') {
            return AiResult::failure('No API key is set. Add it to config/ai.php.');
        }

        $generation = ['temperature' => 0.2, 'maxOutputTokens' => $maxTokens];
        if ($wantJson) {
            $generation['responseMimeType'] = 'application/json';
        }

        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents'          => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
            'generationConfig'  => $generation,
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
             . rawurlencode(AI_MODEL) . ':generateContent';

        $res = $this->post($url, $payload, ['x-goog-api-key: ' . AI_API_KEY]);

        if (!$res['ok']) {
            return AiResult::failure((string) $res['error'], $res['latencyMs'], $res['attempts']);
        }

        $json = json_decode($res['body'], true);
        $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!is_string($text) || trim($text) === '') {
            return AiResult::failure(
                'The provider returned no content.', $res['latencyMs'], $res['attempts']
            );
        }

        return new AiResult(
            true,
            $text,
            $wantJson ? $this->decodeJson($text) : null,
            null,
            $res['latencyMs'],
            $res['attempts'],
            AI_MODEL
        );
    }
}

/* -------------------------------------------------------------------------
   Mock
   ------------------------------------------------------------------------- */

/**
 * Returns believable output without a network call.
 *
 * This is not only a test fixture. Develop against it, and you can build and
 * debug the whole pipeline without spending a single request from your quota.
 */
final class MockProvider implements AiProvider
{
    /** @var list<AiResult> replies handed out in order, for testing failures */
    private array $queue = [];

    public function name(): string
    {
        return 'mock';
    }

    public function queue(AiResult $result): void
    {
        $this->queue[] = $result;
    }

    public function complete(
        string $systemPrompt,
        string $userPrompt,
        bool $wantJson = false,
        int $maxTokens = 1500
    ): AiResult {
        if ($this->queue !== []) {
            return array_shift($this->queue);
        }

        // Read only the delimited content, mirroring the instruction a real
        // model is given. Reading the whole prompt would let scaffolding words
        // leak in, which is exactly the bug this simulates away.
        $content = $userPrompt;
        /* The tag name must end at a space or the closing bracket. Written as
           `passage[^>]*` the wrapper `<passages>` also matched, and everything
           from it to the first `</passage>` was treated as content, so the
           opening `<passage id="1">` tag leaked into the mock's answer. */
        if (preg_match_all(
            '#<(?:excerpt|passage)(?:\s[^>]*)?>(.*?)</(?:excerpt|passage)>#s',
            $userPrompt,
            $x
        )) {
            $content = implode(" ", $x[1]);
        }

        $payload = match (true) {
            str_contains($systemPrompt, 'multiple-choice practice questions')
                => $this->fakeQuestions($content, $userPrompt),
            str_contains($systemPrompt, 'study companion')
                => $this->fakeChat($content, $userPrompt),
            default
                => $this->fakeTopics($content),
        };

        return new AiResult(
            true,
            json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{}',
            $wantJson ? $payload : null,
            null,
            5,
            1,
            'mock'
        );
    }

    /** @return array{topics:list<array<string,mixed>>} */
    /**
     * A stand-in companion answer, built from the passages it was handed.
     *
     * It deliberately imitates the two behaviours that matter: an answer drawn
     * from the material with its passages cited, and an honest refusal when the
     * passages were flagged as not matching. Demonstrating the grounding rule
     * should not require an API key.
     *
     * @return array{answer:string, used_passages:list<int>, grounded:bool}
     */
    private function fakeChat(string $content, string $userPrompt): array
    {
        $question = '';
        if (preg_match("/Student's question: (.+)$/s", $userPrompt, $m)) {
            $question = trim($m[1]);
        }

        // chat_build_prompt() adds this note when retrieval found nothing.
        $unmatched = str_contains($userPrompt, 'found no passage matching');

        if ($unmatched) {
            return [
                'answer' => 'Your uploaded material does not appear to cover that. '
                    . 'What it does cover is the content of the sections you have '
                    . 'attached. Try asking about one of those, or upload the '
                    . 'document that deals with this question.',
                'used_passages' => [],
                'grounded'      => false,
            ];
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', trim($content), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sentences = array_values(array_filter(
            array_map('trim', $sentences),
            static fn($x) => mb_strlen($x) > 20
        ));

        $body = $sentences
            ? implode(' ', array_slice($sentences, 0, 2))
            : 'Your material covers this, but the passage was too short to quote.';

        $count = max(1, preg_match_all('/<passage id="/', $userPrompt));

        return [
            'answer' => ($question !== '' ? 'On your question, "' . $question . '": ' : '')
                . $body . ' That is what your material states on the point.',
            'used_passages' => range(1, min(2, $count)),
            'grounded'      => true,
        ];
    }

    private function fakeTopics(string $content): array
    {
        // Capitalised phrases of one to three words read as topics, where
        // isolated words do not.
        preg_match_all('/\b[A-Z][a-z]{2,}(?:[ -][A-Z][a-z]{2,}){0,2}\b/', $content, $m);
        $phrases = $m[0] ?? [];
        usort($phrases, static fn($a, $b) => substr_count($b, ' ') <=> substr_count($a, ' '));

        $candidates = [];
        foreach ($phrases as $phrase) {
            $key = mb_strtolower($phrase);
            if (isset($candidates[$key]) || mb_strlen($phrase) < 5) {
                continue;
            }
            $candidates[$key] = $phrase;
            if (count($candidates) >= 3) {
                break;
            }
        }
        $candidates = array_values($candidates);
        if ($candidates === []) {
            $candidates = ['General Concepts'];
        }

        $topics = [];
        foreach ($candidates as $c) {
            $topics[] = [
                'topic'    => $c,
                'summary'  => 'Material covering ' . $c . '.',
                'keywords' => [mb_strtolower($c)],
            ];
        }
        return ['topics' => $topics];
    }

    /**
     * Produce a set that passes validation: four distinct options, the answer
     * repeated verbatim from them, no duplicates. A mock that produced invalid
     * output would make the validator look broken rather than the model.
     *
     * @return array{questions:list<array<string,mixed>>}
     */
    private function fakeQuestions(string $content, string $userPrompt): array
    {
        $count = QUESTIONS_PER_SET;
        if (preg_match('/Number of questions: (\d+)/', $userPrompt, $m)) {
            $count = max(1, (int) $m[1]);
        }
        $topic = 'the topic';
        if (preg_match('/^Topic: (.+)$/m', $userPrompt, $m)) {
            $topic = trim($m[1]);
        }

        // Draw the distractors from real sentences in the passages, so the
        // output looks like something a model would actually return.
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($content), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // Keep whole sentences. Cutting them mid-word made the mock output look
        // like a rendering bug rather than like a model's answer.
        $sentences = array_values(array_filter(
            array_map('trim', $sentences),
            static fn($x) => mb_strlen($x) > 12 && mb_strlen($x) <= 160
        ));

        $questions = [];
        for ($i = 0; $i < $count; $i++) {
            $correct = 'Statement ' . ($i + 1) . ' about ' . $topic;
            $options = [$correct];

            /* Distractors must be distinct within a question, or the validator
               rejects the whole item. Walk the sentence list one step at a time
               and fall back to a generated line when it runs out, rather than
               indexing into it with arithmetic that can land twice on the same
               sentence when the passage is short. */
            $pool  = $sentences;
            $start = count($pool) > 0 ? $i % count($pool) : 0;
            for ($k = 0; $k < 3; $k++) {
                $candidate = isset($pool[($start + $k) % max(1, count($pool))]) && $k < count($pool)
                    ? 'Not this: ' . $pool[($start + $k) % count($pool)]
                    : 'Distractor ' . ($i + 1) . '-' . ($k + 1) . ' for ' . $topic;
                $options[] = $candidate;
            }
            shuffle($options);

            $questions[] = [
                'question'    => 'Question ' . ($i + 1) . ': which statement about ' . $topic . ' is correct?',
                'options'     => $options,
                'answer'      => $correct,
                'explanation' => 'The passages state this directly.',
            ];
        }

        return ['questions' => $questions];
    }
}

/* -------------------------------------------------------------------------
   Factory and call logging
   ------------------------------------------------------------------------- */

/**
 * The provider named in config/ai.php, or one injected for testing.
 */
function ai_provider(?AiProvider $override = null): AiProvider
{
    static $injected = null;

    if ($override !== null) {
        return $injected = $override;
    }
    if ($injected instanceof AiProvider) {
        return $injected;
    }

    return match (AI_DRIVER) {
        'openai-compatible' => new OpenAiCompatibleProvider(),
        'gemini'            => new GeminiProvider(),
        default             => new MockProvider(),
    };
}

/** Clear an injected provider. Tests use this between cases. */
function ai_reset_provider(): void
{
    ai_provider(new MockProvider());
}

/**
 * Record one call in ai_interaction.
 *
 * Log every call from day one. It is your cost and latency record, it is
 * evidence for Chapter IV, and it is the only way to work out what went wrong
 * when a generation produces something strange.
 */
function ai_log(int $userId, ?int $resourceId, string $prompt, AiResult $result): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO ai_interaction (user_id, resource_id, prompt, response)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $resourceId,
            mb_substr($prompt, 0, 60000),
            mb_substr(
                $result->ok
                    ? $result->text
                    : '[failed: ' . (string) $result->error . ']',
                0,
                60000
            ),
        ]);
    } catch (PDOException $e) {
        // Logging must never break the feature it is logging.
        error_log('EduFlex ai_log failed: ' . $e->getMessage());
    }
}
