<?php
/**
 * EduFlex — question generation.
 *
 * Turns a detected topic plus the passages it came from into a practice
 * activity: one learning_activity row and a set of activity_item rows.
 *
 * Two things make this safe to build a study system on.
 *
 * 1. The model is asked for JSON in an exact shape, so the reply is parsed
 *    rather than interpreted.
 * 2. Nothing is stored until it passes validation. A question whose stated
 *    answer is not among its own options is worse than no question at all,
 *    because a learner would be marked wrong for being right.
 */

declare(strict_types=1);

require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../config/database.php';

/** Bloom levels in order, with the weights the mastery model uses. */
const BLOOM_ORDER = ['Remember', 'Understand', 'Apply', 'Analyze', 'Evaluate', 'Create'];

/** How many questions one generated set contains. */
const QUESTIONS_PER_SET = 8;

/** A set with fewer than this many valid questions is rejected outright. */
const QUESTIONS_MIN_VALID = 4;

/** Options per multiple-choice item. */
const QUESTION_OPTION_COUNT = 4;

/** Passages sent with one generation request. */
const QUESTION_CONTEXT_CHUNKS = 3;

/* -------------------------------------------------------------------------
   Choosing the Bloom level
   ------------------------------------------------------------------------- */

/**
 * Which Bloom level the next set for this topic should target.
 *
 * Derived from evidence rather than stored, so it cannot drift out of step
 * with the attempts it is supposed to reflect:
 *
 *   no completed attempt yet   -> Remember, the entry level
 *   last score >= 85           -> step up one level
 *   last score <  60           -> step down one level
 *   otherwise                  -> hold at the same level
 *
 * These thresholds are the same ones documented for the mastery model. Keep
 * them in step with MASTERY_MASTERED_AT in includes/helpers.php.
 */
function questions_next_bloom_level(int $topicProgressId, int $userId): string
{
    $stmt = db()->prepare(
        'SELECT la.bloom_level, aa.score
           FROM activity_attempt aa
           JOIN learning_activity la ON la.activity_id = aa.activity_id
          WHERE aa.user_id = ?
            AND la.topic_progress_id = ?
            AND aa.completed_at IS NOT NULL
            AND aa.score IS NOT NULL
       ORDER BY aa.completed_at DESC, aa.attempt_id DESC
          LIMIT 1'
    );
    $stmt->execute([$userId, $topicProgressId]);
    $last = $stmt->fetch();

    if (!$last) {
        return BLOOM_ORDER[0];
    }

    $index = array_search((string) $last['bloom_level'], BLOOM_ORDER, true);
    if ($index === false) {
        return BLOOM_ORDER[0];
    }

    $score = (float) $last['score'];
    if ($score >= 85.0) {
        $index = min($index + 1, count(BLOOM_ORDER) - 1);
    } elseif ($score < 60.0) {
        $index = max($index - 1, 0);
    }

    return BLOOM_ORDER[$index];
}

/* -------------------------------------------------------------------------
   Retrieval: which passages to send
   ------------------------------------------------------------------------- */

/** Words too common to help identify a relevant passage. */
const QUESTION_STOPWORDS = [
    'the', 'a', 'an', 'and', 'or', 'of', 'in', 'to', 'for', 'with',
    'on', 'at', 'by', 'from', 'as', 'is', 'are', 'its', 'this', 'that',
];

/**
 * Score every chunk of a resource against the topic name and return the best.
 *
 * This is deliberately simple keyword matching rather than embeddings. At the
 * scale of one learner's own uploads it is accurate enough, it needs no vector
 * store, and it costs nothing. If retrieval quality ever becomes the limiting
 * factor, this is the function to replace.
 *
 * @return list<array{chunk_index:int, content:string, score:int}>
 */
function questions_select_context(int $resourceId, string $topicName, int $max = QUESTION_CONTEXT_CHUNKS): array
{
    $stmt = db()->prepare(
        'SELECT chunk_index, content FROM resource_chunk
          WHERE resource_id = ? ORDER BY chunk_index ASC'
    );
    $stmt->execute([$resourceId]);
    $chunks = $stmt->fetchAll();

    if (!$chunks) {
        return [];
    }

    $terms = array_filter(
        preg_split('/[^a-z0-9]+/', mb_strtolower($topicName), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        static fn(string $w): bool => mb_strlen($w) > 2 && !in_array($w, QUESTION_STOPWORDS, true)
    );

    $scored = [];
    foreach ($chunks as $chunk) {
        $haystack = mb_strtolower((string) $chunk['content']);
        $score = 0;
        foreach ($terms as $term) {
            $score += substr_count($haystack, $term);
        }
        $scored[] = [
            'chunk_index' => (int) $chunk['chunk_index'],
            'content'     => (string) $chunk['content'],
            'score'       => $score,
        ];
    }

    // Best match first; ties keep document order so context reads naturally.
    usort($scored, static function (array $a, array $b): int {
        return $b['score'] <=> $a['score'] ?: $a['chunk_index'] <=> $b['chunk_index'];
    });

    $top = array_slice($scored, 0, $max);

    // No term matched anywhere. Fall back to the opening passages rather than
    // returning nothing, so a topic with an awkward name still generates.
    if ($top && $top[0]['score'] === 0) {
        $top = array_slice($scored, 0, $max);
        usort($top, static fn(array $a, array $b): int => $a['chunk_index'] <=> $b['chunk_index']);
    }

    return array_values($top);
}

/* -------------------------------------------------------------------------
   The prompt
   ------------------------------------------------------------------------- */

function questions_system_prompt(): string
{
    return <<<'PROMPT'
You write multiple-choice practice questions for a student, from passages of
their own study material.

Hard requirements. A question breaking any of these is useless and must not be
produced:
- Every question must be answerable from the passages alone. Never rely on
  outside knowledge, and never invent facts the passages do not contain.
- Exactly 4 options per question, labelled by their full text, not by letter.
- "answer" must repeat one of the 4 options word for word.
- Exactly one option may be correct. The other three must be clearly wrong to
  someone who understands the passage, not merely less good.
- No "all of the above", "none of the above", or "both A and B".
- Options should be similar in length and style, so length does not give the
  answer away.
- Never mention "the passage", "the excerpt" or "the document" in a question.
  Ask about the subject itself.
- The explanation states why the answer is right, in one or two sentences.

The requested Bloom's Taxonomy level governs what the question demands:
- Remember: recall a definition, term or stated fact.
- Understand: explain a concept in different words, or classify an example.
- Apply: use a rule or procedure in a specific situation.
- Analyze: compare, contrast, or identify how parts relate.
- Evaluate: judge which option best meets a stated criterion, and why.
- Create: choose the best design or approach for a new situation.

Reply with JSON only, in exactly this shape:

{
  "questions": [
    {
      "question": "The question text.",
      "options": ["First option", "Second option", "Third option", "Fourth option"],
      "answer": "The option that is correct, copied exactly.",
      "explanation": "Why that option is correct."
    }
  ]
}
PROMPT;
}

/* -------------------------------------------------------------------------
   Validation
   ------------------------------------------------------------------------- */

/**
 * Keep only questions that are safe to score a learner against.
 *
 * Returns the valid ones plus a count of what was thrown away and why, so the
 * caller can tell the difference between "the model had an off day" and "this
 * topic has no usable material".
 *
 * @return array{items:list<array<string,mixed>>, rejected:int, reasons:array<string,int>}
 */
function questions_validate(?array $data): array
{
    $reasons = [];
    $items   = [];
    $reject  = static function (string $why) use (&$reasons): void {
        $reasons[$why] = ($reasons[$why] ?? 0) + 1;
    };

    $list = is_array($data) ? ($data['questions'] ?? $data) : null;
    if (!is_array($list)) {
        return ['items' => [], 'rejected' => 0, 'reasons' => ['unreadable reply' => 1]];
    }

    $seen = [];

    foreach ($list as $row) {
        if (!is_array($row)) {
            $reject('not an object');
            continue;
        }

        $text = $row['question'] ?? $row['question_text'] ?? null;
        if (!is_string($text) || mb_strlen(trim($text)) < 10) {
            $reject('missing or trivial question text');
            continue;
        }
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        // The same question twice in one set wastes the learner's time and
        // double-counts toward mastery.
        $key = mb_strtolower($text);
        if (isset($seen[$key])) {
            $reject('duplicate question');
            continue;
        }

        $options = $row['options'] ?? null;
        if (!is_array($options)) {
            $reject('options missing');
            continue;
        }

        $clean = [];
        foreach ($options as $option) {
            if (!is_string($option)) {
                continue;
            }
            $option = trim(preg_replace('/\s+/u', ' ', $option) ?? $option);
            // Strip a leading "A) " or "1. " if the model added one anyway.
            $option = (string) preg_replace('/^\s*(?:[A-Da-d][\).:]|\d[\).:])\s*/', '', $option);
            if ($option !== '') {
                $clean[] = $option;
            }
        }

        if (count($clean) !== QUESTION_OPTION_COUNT) {
            $reject('wrong number of options');
            continue;
        }

        // Two identical options make the question unanswerable.
        if (count(array_unique(array_map('mb_strtolower', $clean))) !== QUESTION_OPTION_COUNT) {
            $reject('duplicate options');
            continue;
        }

        foreach ($clean as $option) {
            if (preg_match('/\b(all|none) of the above\b/i', $option)) {
                $reject('forbidden option phrasing');
                continue 2;
            }
        }

        $answer = $row['answer'] ?? $row['correct_answer'] ?? null;
        if (!is_string($answer) || trim($answer) === '') {
            $reject('answer missing');
            continue;
        }
        $answer = trim(preg_replace('/\s+/u', ' ', $answer) ?? $answer);
        $answer = (string) preg_replace('/^\s*(?:[A-Da-d][\).:]|\d[\).:])\s*/', '', $answer);

        // THE critical check. If the stated answer is not one of the options,
        // the learner cannot select it and would be marked wrong for being
        // right. Match case-insensitively, then store the option's own casing.
        $matchIndex = null;
        foreach ($clean as $i => $option) {
            if (mb_strtolower($option) === mb_strtolower($answer)) {
                $matchIndex = $i;
                break;
            }
        }
        if ($matchIndex === null) {
            $reject('answer is not one of the options');
            continue;
        }

        $explanation = $row['explanation'] ?? $row['rationale'] ?? '';
        $explanation = is_string($explanation)
            ? mb_substr(trim($explanation), 0, 1000)
            : '';

        $seen[$key] = true;
        $items[] = [
            'question'    => mb_substr($text, 0, 2000),
            'options'     => $clean,
            'answer'      => $clean[$matchIndex],
            'explanation' => $explanation,
        ];
    }

    return [
        'items'    => $items,
        'rejected' => array_sum($reasons),
        'reasons'  => $reasons,
    ];
}

/* -------------------------------------------------------------------------
   Generation
   ------------------------------------------------------------------------- */

/**
 * Generate and store one practice activity for a topic.
 *
 * @return array{ok:bool, activity_id:?int, items:int, rejected:int,
 *               bloom:string, error:?string, reasons:array<string,int>}
 */
function questions_generate(int $topicProgressId, int $userId, ?string $bloomLevel = null): array
{
    $fail = static fn(string $m, array $extra = []): array => array_merge([
        'ok' => false, 'activity_id' => null, 'items' => 0, 'rejected' => 0,
        'bloom' => '', 'error' => $m, 'reasons' => [],
    ], $extra);

    $stmt = db()->prepare(
        'SELECT tp.topic_progress_id, tp.topic_name, tp.resource_id,
                lr.title AS resource_title, lr.processing_status
           FROM topic_progress tp
           JOIN learning_resource lr ON lr.resource_id = tp.resource_id
          WHERE tp.topic_progress_id = ? AND tp.user_id = ? LIMIT 1'
    );
    $stmt->execute([$topicProgressId, $userId]);
    $topic = $stmt->fetch();

    if (!$topic) {
        return $fail('That topic was not found.');
    }
    if ($topic['processing_status'] !== 'processed') {
        return $fail('The source material has not been read yet.');
    }

    $context = questions_select_context((int) $topic['resource_id'], (string) $topic['topic_name']);
    if (!$context) {
        return $fail('No stored passages were found for that material.');
    }

    $bloom = $bloomLevel !== null && in_array($bloomLevel, BLOOM_ORDER, true)
        ? $bloomLevel
        : questions_next_bloom_level($topicProgressId, $userId);

    $passages = '';
    foreach ($context as $i => $chunk) {
        $passages .= "<passage id=\"" . ($i + 1) . "\">\n" . $chunk['content'] . "\n</passage>\n\n";
    }

    $userPrompt = "Topic: " . $topic['topic_name'] . "\n"
                . "Bloom's Taxonomy level: " . $bloom . "\n"
                . "Number of questions: " . QUESTIONS_PER_SET . "\n\n"
                . "Write the questions from these passages only.\n\n"
                . $passages;

    $result = ai_provider()->complete(questions_system_prompt(), $userPrompt, true, 2600);
    ai_log($userId, (int) $topic['resource_id'], $userPrompt, $result);

    if (!$result->ok) {
        return $fail((string) $result->error, ['bloom' => $bloom]);
    }

    $checked = questions_validate($result->data ?? []);

    if (count($checked['items']) < QUESTIONS_MIN_VALID) {
        return $fail(
            'Only ' . count($checked['items']) . ' of the generated questions were usable. '
            . 'Try again, or pick a different topic.',
            [
                'bloom'    => $bloom,
                // The usable count is reported even though the set is refused,
                // so a caller can tell "nothing survived" from "three survived
                // but the floor is four". tools/rubric_run.php needs that
                // distinction; without it a discarded set looks the same as a
                // provider that returned nothing at all.
                'items'    => count($checked['items']),
                'rejected' => $checked['rejected'],
                'reasons'  => $checked['reasons'],
            ]
        );
    }

    $items = array_slice($checked['items'], 0, QUESTIONS_PER_SET);

    try {
        db()->beginTransaction();

        $stmt = db()->prepare(
            'INSERT INTO learning_activity
                (resource_id, topic_progress_id, activity_type, title, bloom_level, difficulty_level)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $topic['resource_id'],
            $topicProgressId,
            'practice_set',
            mb_substr($topic['topic_name'] . ': practice set', 0, 255),
            $bloom,
            questions_difficulty_for_bloom($bloom),
        ]);
        $activityId = (int) db()->lastInsertId();

        $insert = db()->prepare(
            'INSERT INTO activity_item
                (activity_id, question_text, item_type, options_json, correct_answer, explanation)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as $item) {
            $insert->execute([
                $activityId,
                $item['question'],
                'multiple_choice',
                json_encode($item['options'], JSON_UNESCAPED_UNICODE),
                $item['answer'],
                $item['explanation'] !== '' ? $item['explanation'] : null,
            ]);
        }

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('EduFlex activity insert failed: ' . $e->getMessage());
        return $fail('The questions could not be saved.', ['bloom' => $bloom]);
    }

    return [
        'ok'          => true,
        'activity_id' => $activityId,
        'items'       => count($items),
        'rejected'    => $checked['rejected'],
        'bloom'       => $bloom,
        'error'       => $checked['rejected'] > 0
            ? $checked['rejected'] . ' generated question(s) failed validation and were discarded.'
            : null,
        'reasons'     => $checked['reasons'],
    ];
}

/**
 * A plain-language difficulty label, derived from the Bloom level so the two
 * can never disagree.
 */
function questions_difficulty_for_bloom(string $bloom): string
{
    return match ($bloom) {
        'Remember', 'Understand' => 'foundational',
        'Apply', 'Analyze'       => 'intermediate',
        default                  => 'advanced',
    };
}

/**
 * Activities available to a learner, newest first.
 *
 * @return list<array<string,mixed>>
 */
function questions_activity_list(int $userId, int $limit = 20): array
{
    $stmt = db()->prepare(
        'SELECT la.activity_id, la.title, la.bloom_level, la.difficulty_level,
                la.generated_at, tp.topic_name, tp.mastery_score, tp.scored_items,
                COUNT(ai.item_id) AS item_count,
                (SELECT COUNT(*) FROM activity_attempt aa
                  WHERE aa.activity_id = la.activity_id AND aa.user_id = ?) AS attempts
           FROM learning_activity la
           JOIN learning_resource lr ON lr.resource_id = la.resource_id
      LEFT JOIN topic_progress tp    ON tp.topic_progress_id = la.topic_progress_id
      LEFT JOIN activity_item ai     ON ai.activity_id = la.activity_id
          WHERE lr.user_id = ?
       GROUP BY la.activity_id
       ORDER BY la.generated_at DESC, la.activity_id DESC
          LIMIT ' . (int) $limit
    );
    $stmt->execute([$userId, $userId]);
    return $stmt->fetchAll();
}

/**
 * Topics that could have a set generated for them, weakest first.
 *
 * @return list<array<string,mixed>>
 */
function questions_generatable_topics(int $userId, int $limit = 20): array
{
    $stmt = db()->prepare(
        'SELECT tp.topic_progress_id, tp.topic_name, tp.mastery_score, tp.scored_items,
                lr.title AS resource_title,
                (SELECT COUNT(*) FROM learning_activity la
                  WHERE la.topic_progress_id = tp.topic_progress_id) AS activity_count
           FROM topic_progress tp
           JOIN learning_resource lr ON lr.resource_id = tp.resource_id
          WHERE tp.user_id = ? AND lr.processing_status = ?
       ORDER BY (tp.scored_items >= ' . MASTERY_MIN_ITEMS . ') DESC,
                tp.mastery_score ASC
          LIMIT ' . (int) $limit
    );
    $stmt->execute([$userId, 'processed']);
    return $stmt->fetchAll();
}
