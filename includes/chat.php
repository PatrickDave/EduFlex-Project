<?php
/**
 * EduFlex — the AI Learning Companion's conversation.
 *
 * This is the feature the system is named after, and the one a panel is most
 * likely to probe. Two rules govern everything here:
 *
 *   1. The companion answers from the learner's own uploaded material and from
 *      nothing else. Passages are retrieved first, the model sees only those,
 *      and it is told to say so plainly when they do not contain the answer.
 *      A confident answer drawn from the model's own training is exactly the
 *      failure this design exists to prevent.
 *
 *   2. Every answer names the passages it used. The interface turns those into
 *      source chips, so a learner can check the claim against the document
 *      rather than taking it on trust. This is the Responsible AI commitment in
 *      Chapter I made mechanical.
 *
 * Retrieval is keyword scoring over `resource_chunk`, the same approach used by
 * question generation. It is deliberately not embeddings: at one learner's
 * scale a vector store would add a dependency and a running cost without
 * measurably better recall.
 */

declare(strict_types=1);

require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/questions.php';

/** How many passages are put in front of the model for one question. */
const CHAT_CONTEXT_CHUNKS = 4;

/** How many previous exchanges are replayed so follow-up questions work. */
const CHAT_HISTORY_TURNS = 6;

/** Characters of history sent at most, so a long conversation cannot grow the
 *  prompt without limit and quietly cost more with every message. */
const CHAT_HISTORY_BUDGET = 4000;

/** A question longer than this is refused before it reaches the provider. */
const CHAT_MAX_QUESTION = 1000;

/* -------------------------------------------------------------------------
   Retrieval
   ------------------------------------------------------------------------- */

/**
 * Reduce a question to the words worth matching on.
 *
 * Trailing plurals are trimmed so "harmonics" finds a passage that says
 * "harmonic". This is crude next to a real stemmer and that is the point: it
 * costs nothing, and its behaviour can be stated in one sentence in Chapter III.
 *
 * @return list<string>
 */
function chat_query_terms(string $question): array
{
    $words = preg_split('/[^a-z0-9]+/', mb_strtolower($question), -1, PREG_SPLIT_NO_EMPTY) ?: [];

    $terms = [];
    foreach ($words as $word) {
        if (mb_strlen($word) <= 2 || in_array($word, CHAT_STOPWORDS, true)) {
            continue;
        }
        $terms[$word] = true;
        if (mb_strlen($word) > 4 && str_ends_with($word, 's')) {
            $terms[mb_substr($word, 0, -1)] = true;
        }
    }

    /* Cast back to string. PHP silently turns a numeric array key into an
       integer, so a question mentioning a year handed substr_count() an int
       and brought the whole conversation down with a TypeError. */
    return array_values(array_map('strval', array_keys($terms)));
}

/**
 * Words that carry no topical meaning. Longer than the generation list because
 * a learner writes questions, not topic names: "what", "how" and "explain"
 * appear in almost every message and would match almost every passage.
 */
const CHAT_STOPWORDS = [
    'the', 'a', 'an', 'and', 'or', 'of', 'in', 'to', 'for', 'with', 'on', 'at',
    'by', 'from', 'as', 'is', 'are', 'was', 'were', 'be', 'been', 'its', 'this',
    'that', 'these', 'those', 'it', 'you', 'your', 'me', 'my', 'we', 'our',
    'what', 'why', 'how', 'when', 'where', 'which', 'who', 'can', 'could',
    'would', 'should', 'do', 'does', 'did', 'not', 'but', 'if', 'then', 'than',
    'about', 'into', 'more', 'most', 'some', 'any', 'all', 'please', 'tell',
    'explain', 'describe', 'give', 'show', 'help', 'mean', 'means', 'there',
    'here', 'they', 'them', 'their', 'have', 'has', 'had', 'will', 'just',
    // Instruction verbs. A learner writing "summarise this" is stating what
    // they want done, not what they want found, and matching on the verb
    // would score every passage that happens to contain it.
    'summarise', 'summarize', 'summary', 'outline', 'list', 'compare',
    'discuss', 'elaborate', 'clarify', 'again', 'much', 'many', 'know',
    'understand', 'want', 'need', 'make', 'get', 'say', 'also', 'like',
];

/**
 * Find the passages most likely to answer a question.
 *
 * Searches every processed document the learner owns, not only one, because a
 * question rarely respects the boundary between two uploads.
 *
 * @param int      $userId
 * @param string   $question
 * @param int|null $resourceId restrict to one document, or null for all
 * @return array{passages:list<array{resource_id:int,title:string,chunk_index:int,content:string,score:int}>, confident:bool, searchable:bool}
 */
function chat_select_context(
    int $userId,
    string $question,
    ?int $resourceId = null,
    int $max = CHAT_CONTEXT_CHUNKS
): array {
    $sql = 'SELECT rc.resource_id, rc.chunk_index, rc.content, lr.title
              FROM resource_chunk rc
              JOIN learning_resource lr ON lr.resource_id = rc.resource_id
             WHERE lr.user_id = ? AND lr.processing_status = \'processed\'';
    $params = [$userId];

    if ($resourceId !== null) {
        $sql .= ' AND rc.resource_id = ?';
        $params[] = $resourceId;
    }
    $sql .= ' ORDER BY rc.resource_id ASC, rc.chunk_index ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $chunks = $stmt->fetchAll();

    if (!$chunks) {
        return ['passages' => [], 'confident' => false, 'searchable' => false];
    }

    $terms = chat_query_terms($question);

    $scored = [];
    foreach ($chunks as $chunk) {
        $haystack = mb_strtolower((string) $chunk['content']);
        $score = 0;
        foreach ($terms as $term) {
            $score += substr_count($haystack, $term);
        }
        $scored[] = [
            'resource_id' => (int) $chunk['resource_id'],
            'title'       => (string) $chunk['title'],
            'chunk_index' => (int) $chunk['chunk_index'],
            'content'     => (string) $chunk['content'],
            'score'       => $score,
        ];
    }

    usort($scored, static function (array $a, array $b): int {
        return $b['score'] <=> $a['score']
            ?: ($a['resource_id'] <=> $b['resource_id']
            ?: $a['chunk_index'] <=> $b['chunk_index']);
    });

    $top = array_slice($scored, 0, $max);

    /* Nothing matched. That happens two ways, and they are not the same thing.
       A question with no content words at all ("summarise this") legitimately
       has nothing to match on, so the opening passages are the right answer. A
       question full of content words that appear nowhere is probably off this
       material, and the model is told as much rather than being left to guess. */
    $confident = $top !== [] && $top[0]['score'] > 0;
    if (!$confident) {
        $top = array_slice($scored, 0, $max);
        usort($top, static function (array $a, array $b): int {
            return $a['resource_id'] <=> $b['resource_id']
                ?: $a['chunk_index'] <=> $b['chunk_index'];
        });
    }

    return [
        'passages'   => $top,
        'confident'  => $confident || $terms === [],
        'searchable' => true,
    ];
}

/* -------------------------------------------------------------------------
   Prompting
   ------------------------------------------------------------------------- */

/**
 * The instruction that keeps the companion honest.
 *
 * Quote it in Chapter III verbatim. It is the mechanism behind the claim that
 * the system is grounded in the learner's own material.
 */
function chat_system_prompt(): string
{
    return <<<'PROMPT'
You are EduFlex, a study companion for a university student.

You answer ONLY from the passages provided in the <passages> block. Those
passages are extracted from documents the student uploaded themselves.

Rules, in order of importance:
1. If the passages do not contain the answer, say so plainly and say what the
   material does cover instead. Never fill the gap from your own knowledge, and
   never guess. An honest "your material does not cover this" is a correct
   answer, not a failure.
2. Cite the passages you used by their id numbers.
3. Explain in plain language a second-year undergraduate would follow. Define a
   term the first time you use it.
4. Be concise. Three short paragraphs at most unless the student asks for more.
5. Never invent a figure, date, formula or citation that is not in the passages.
6. If the student asks you to do their graded work for them, help them
   understand it instead.

Reply with JSON only, in exactly this shape:

{
  "answer": "your reply to the student, as plain text",
  "used_passages": [1, 3],
  "grounded": true
}

Set "grounded" to false when the passages did not contain the answer and you
had to tell the student so. Leave "used_passages" empty in that case.
PROMPT;
}

/**
 * Build the user-side prompt: the passages, the recent conversation, the question.
 *
 * @param list<array<string,mixed>> $passages
 * @param list<array{question:string,answer:string}> $history
 */
function chat_build_prompt(array $passages, array $history, string $question, bool $confident): string
{
    $out = '';

    if ($history) {
        $out .= "Earlier in this conversation:\n";
        foreach ($history as $turn) {
            $out .= "Student: " . $turn['question'] . "\n";
            $out .= "You: " . $turn['answer'] . "\n";
        }
        $out .= "\n";
    }

    $out .= "<passages>\n";
    foreach ($passages as $i => $p) {
        $out .= '<passage id="' . ($i + 1) . '" source="' . $p['title'] . '">' . "\n";
        $out .= $p['content'] . "\n";
        $out .= "</passage>\n\n";
    }
    $out .= "</passages>\n\n";

    if (!$confident) {
        $out .= "Note: a search of the student's material found no passage matching "
              . "this question. The passages above are the opening sections, included "
              . "only for context. Unless they genuinely answer the question, tell the "
              . "student their material does not cover it.\n\n";
    }

    $out .= "Student's question: " . $question . "\n";

    return $out;
}

/* -------------------------------------------------------------------------
   History
   ------------------------------------------------------------------------- */

/**
 * The learner's recent exchanges, oldest first, trimmed to a character budget.
 *
 * Without this a follow-up such as "why?" has no referent. With it, the prompt
 * still cannot grow without limit however long the learner talks.
 *
 * @return list<array{question:string,answer:string}>
 */
function chat_history(int $userId, int $turns = CHAT_HISTORY_TURNS): array
{
    $stmt = db()->prepare(
        'SELECT prompt, response FROM ai_interaction
          WHERE user_id = ? AND prompt LIKE \'chat:%\'
       ORDER BY interaction_id DESC LIMIT ' . max(1, $turns)
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    $history = [];
    $budget  = CHAT_HISTORY_BUDGET;

    foreach ($rows as $row) {
        $question = mb_substr((string) $row['prompt'], 5);           // strip "chat:"
        $answer   = (string) $row['response'];

        // A failed call was logged with a bracketed marker, not an answer.
        if (str_starts_with($answer, '[failed:')) {
            continue;
        }

        $decoded = json_decode($answer, true);
        if (is_array($decoded) && isset($decoded['answer'])) {
            $answer = (string) $decoded['answer'];
        }

        $cost = mb_strlen($question) + mb_strlen($answer);
        if ($cost > $budget) {
            break;
        }
        $budget -= $cost;

        $history[] = ['question' => $question, 'answer' => $answer];
    }

    return array_reverse($history);
}

/* -------------------------------------------------------------------------
   Reading the reply
   ------------------------------------------------------------------------- */

/**
 * Turn a model reply into something the screen can render safely.
 *
 * A reply that is not the agreed JSON is still usable as plain text, so a
 * provider that ignores the format instruction degrades rather than fails.
 *
 * @param array<string,mixed>|null $data decoded JSON, if the provider gave any
 * @param list<array<string,mixed>> $passages
 * @return array{answer:string, sources:list<array{title:string,chunk_index:int,resource_id:int}>, grounded:bool}
 */
function chat_parse_reply(?array $data, string $rawText, array $passages): array
{
    $answer   = '';
    $used     = [];
    $grounded = true;

    if (is_array($data) && isset($data['answer']) && is_string($data['answer'])) {
        $answer   = trim($data['answer']);
        $grounded = !isset($data['grounded']) || (bool) $data['grounded'];
        if (isset($data['used_passages']) && is_array($data['used_passages'])) {
            foreach ($data['used_passages'] as $id) {
                if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                    $used[] = (int) $id;
                }
            }
        }
    } else {
        // Not JSON. Use whatever text came back rather than showing an error
        // for a reply that may be perfectly good.
        $answer = trim($rawText);
    }

    if ($answer === '') {
        $answer = 'I could not produce an answer for that. Try rephrasing the question.';
        $grounded = false;
    }

    $sources = [];
    $seen    = [];
    foreach ($used as $id) {
        $index = $id - 1;                       // passage ids are 1-based
        if (!isset($passages[$index])) {
            continue;                            // a hallucinated citation is dropped
        }
        $p   = $passages[$index];
        $key = $p['resource_id'] . ':' . $p['chunk_index'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $sources[] = [
            'title'       => (string) $p['title'],
            'chunk_index' => (int) $p['chunk_index'],
            'resource_id' => (int) $p['resource_id'],
        ];
    }

    return ['answer' => $answer, 'sources' => $sources, 'grounded' => $grounded];
}

/* -------------------------------------------------------------------------
   Asking
   ------------------------------------------------------------------------- */

/**
 * Answer one question from the learner's material.
 *
 * @return array{ok:bool, answer:?string, sources:list<array<string,mixed>>,
 *               grounded:bool, error:?string}
 */
function chat_ask(int $userId, string $question, ?int $resourceId = null): array
{
    $fail = static fn(string $m): array => [
        'ok' => false, 'answer' => null, 'sources' => [],
        'grounded' => false, 'error' => $m,
    ];

    $question = trim($question);
    if ($question === '') {
        return $fail('Type a question first.');
    }
    if (mb_strlen($question) > CHAT_MAX_QUESTION) {
        return $fail('That question is too long. Keep it under '
            . CHAT_MAX_QUESTION . ' characters.');
    }

    // Scoping to one document is the learner's choice, so it is checked here
    // rather than trusted. Retrieval filters by owner too; this gives a clear
    // message instead of a silently empty result.
    if ($resourceId !== null) {
        $stmt = db()->prepare(
            'SELECT resource_id FROM learning_resource
              WHERE resource_id = ? AND user_id = ? LIMIT 1'
        );
        $stmt->execute([$resourceId, $userId]);
        if (!$stmt->fetch()) {
            return $fail('That material was not found.');
        }
    }

    $context = chat_select_context($userId, $question, $resourceId);

    if (!$context['searchable']) {
        return $fail('Upload a document first. I answer from your own material, '
            . 'so I have nothing to read yet.');
    }

    $prompt = chat_build_prompt(
        $context['passages'],
        chat_history($userId),
        $question,
        $context['confident']
    );

    $result = ai_provider()->complete(chat_system_prompt(), $prompt, true, 900);

    // Logged under a "chat:" prefix so chat_history() can find its own turns
    // among topic detection and question generation calls.
    ai_log($userId, $resourceId, 'chat:' . $question, $result);

    if (!$result->ok) {
        return $fail((string) ($result->error ?? 'The AI provider did not answer.'));
    }

    $parsed = chat_parse_reply($result->data, $result->text, $context['passages']);

    return [
        'ok'       => true,
        'answer'   => $parsed['answer'],
        'sources'  => $parsed['sources'],
        'grounded' => $parsed['grounded'],
        'error'    => null,
    ];
}

/**
 * The conversation so far, oldest first, for rendering the thread on load.
 *
 * @return list<array{question:string, answer:string, sources:list<array<string,mixed>>, created_at:string}>
 */
function chat_thread(int $userId, int $limit = 30): array
{
    $stmt = db()->prepare(
        'SELECT prompt, response, created_at FROM ai_interaction
          WHERE user_id = ? AND prompt LIKE \'chat:%\'
       ORDER BY interaction_id DESC LIMIT ' . max(1, $limit)
    );
    $stmt->execute([$userId]);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $answer = (string) $row['response'];
        if (str_starts_with($answer, '[failed:')) {
            continue;
        }
        $decoded = json_decode($answer, true);
        if (is_array($decoded) && isset($decoded['answer'])) {
            $answer = (string) $decoded['answer'];
        }
        $out[] = [
            'question'   => mb_substr((string) $row['prompt'], 5),
            'answer'     => $answer,
            'sources'    => [],
            'created_at' => (string) $row['created_at'],
        ];
    }

    return array_reverse($out);
}

/**
 * Forget the conversation. The learner owns it, so they can clear it.
 */
function chat_clear(int $userId): bool
{
    try {
        $stmt = db()->prepare(
            'DELETE FROM ai_interaction WHERE user_id = ? AND prompt LIKE \'chat:%\''
        );
        $stmt->execute([$userId]);
        return true;
    } catch (PDOException $e) {
        error_log('EduFlex chat_clear failed: ' . $e->getMessage());
        return false;
    }
}
