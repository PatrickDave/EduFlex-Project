<?php
/**
 * EduFlex — topic detection.
 *
 * Reads the chunks produced by extraction and works out what the document
 * actually covers, writing one topic_progress row per topic.
 *
 * This is the first step that costs quota, so it is careful about how much it
 * spends. See the sampling note on topics_select_chunks().
 */

declare(strict_types=1);

require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/../config/database.php';

const TOPIC_MIN_NAME_LEN = 3;
const TOPIC_MAX_NAME_LEN = 120;

/**
 * The instruction that shapes every topic-detection call.
 *
 * Two things make this work. First, it asks for JSON with an exact shape, so
 * the reply can be parsed rather than interpreted. Second, it forbids invention:
 * the model must name topics that are present in the excerpt, because a topic
 * EduFlex cannot generate questions about is worse than no topic at all.
 */
function topics_system_prompt(): string
{
    return <<<'PROMPT'
You identify the academic topics covered by an excerpt of a student's study material.

Rules:
- Only name topics that genuinely appear in the excerpt. Never invent one.
- A topic is a teachable concept, not a section heading and not the document title.
  "Fourier Series" is a topic. "Chapter 3" and "Introduction" are not.
- Merge near-duplicates into one topic.
- Between 1 and 6 topics per excerpt. Fewer is better than padding.
- If the excerpt is a title page, a reference list, or otherwise has no teachable
  content, return an empty topics array.
- Analyse ONLY the text between <excerpt> and </excerpt>. Everything outside those
  tags is context for you, not material to draw topics from. Never turn the
  document title, a page number or the word "excerpt" into a topic.

Reply with JSON only, in exactly this shape:

{
  "topics": [
    {
      "topic": "Short topic name, 2 to 6 words",
      "summary": "One sentence on what the excerpt says about it.",
      "keywords": ["term", "term"]
    }
  ]
}
PROMPT;
}

/**
 * Choose which chunks to send.
 *
 * A 205-chunk document would cost 205 requests if every chunk were sent, which
 * exhausts a free tier on one upload. Instead take an evenly spaced sample
 * across the whole document, so the topics reflect the end as well as the
 * beginning. Short documents are covered completely.
 *
 * @param list<array<string,mixed>> $chunks
 * @return list<array<string,mixed>>
 */
function topics_select_chunks(array $chunks, int $max = AI_MAX_CHUNKS_PER_RESOURCE): array
{
    $total = count($chunks);
    if ($total === 0) {
        return [];
    }
    if ($total <= $max) {
        return $chunks;
    }

    $step     = $total / $max;
    $selected = [];
    for ($i = 0; $i < $max; $i++) {
        $index = (int) floor($i * $step);
        if ($index >= $total) {
            $index = $total - 1;
        }
        $selected[$index] = $chunks[$index];   // keyed, so a collision cannot duplicate
    }

    return array_values($selected);
}

/**
 * Normalise a topic name so the same idea from two chunks merges into one row.
 */
function topics_normalise_key(string $name): string
{
    $key = mb_strtolower(trim($name));
    $key = (string) preg_replace('/[^a-z0-9 ]+/u', ' ', $key);
    $key = (string) preg_replace('/\s+/', ' ', $key);
    // Drop a leading article so "The Chain Rule" and "Chain Rule" agree.
    $key = (string) preg_replace('/^(the|a|an) /', '', $key);
    return trim($key);
}

/**
 * Tidy a name for display without changing its meaning.
 */
function topics_clean_name(string $name): string
{
    $name = trim((string) preg_replace('/\s+/', ' ', $name));
    $name = trim($name, " \t\n\r\0\x0B.,;:-–—");
    return mb_substr($name, 0, TOPIC_MAX_NAME_LEN);
}

/**
 * Pull valid topics out of one model reply, discarding anything malformed.
 *
 * Never trust the shape. A model can return the right JSON with the wrong keys,
 * an empty name, or a paragraph where a name should be.
 *
 * @return list<array{topic:string, summary:string}>
 */
function topics_parse_reply(?array $data): array
{
    if (!is_array($data)) {
        return [];
    }

    // Accept {"topics":[...]} and a bare [...] alike.
    $list = $data['topics'] ?? $data;
    if (!is_array($list)) {
        return [];
    }

    $out = [];
    foreach ($list as $row) {
        if (is_string($row)) {
            $row = ['topic' => $row];
        }
        if (!is_array($row)) {
            continue;
        }

        $name = $row['topic'] ?? $row['name'] ?? null;
        if (!is_string($name)) {
            continue;
        }

        $name = topics_clean_name($name);
        if (mb_strlen($name) < TOPIC_MIN_NAME_LEN) {
            continue;
        }
        // A "topic" running to a whole sentence is a summary in the wrong field.
        if (str_word_count($name) > 10) {
            continue;
        }

        $summary = $row['summary'] ?? $row['description'] ?? '';
        $out[] = [
            'topic'   => $name,
            'summary' => is_string($summary) ? mb_substr(trim($summary), 0, 500) : '',
        ];
    }

    return $out;
}

/**
 * Detect the topics in one resource and store them.
 *
 * Safe to run again: existing topics keep their mastery scores, and only new
 * ones are inserted. That matters because re-running must never wipe a
 * learner's recorded progress.
 *
 * @return array{ok:bool, topics:int, new:int, calls:int, failed:int,
 *               chunks:int, error:?string, names:list<string>}
 */
function topics_detect(int $resourceId, int $userId): array
{
    $fail = static fn(string $m): array => [
        'ok' => false, 'topics' => 0, 'new' => 0, 'calls' => 0,
        'failed' => 0, 'chunks' => 0, 'error' => $m, 'names' => [],
    ];

    $stmt = db()->prepare(
        'SELECT resource_id, title, processing_status
           FROM learning_resource
          WHERE resource_id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$resourceId, $userId]);
    $resource = $stmt->fetch();

    if (!$resource) {
        return $fail('Resource not found.');
    }
    if ($resource['processing_status'] !== 'processed') {
        return $fail('That material has not been read yet.');
    }

    $stmt = db()->prepare(
        'SELECT chunk_index, content FROM resource_chunk
          WHERE resource_id = ? ORDER BY chunk_index ASC'
    );
    $stmt->execute([$resourceId]);
    $chunks = $stmt->fetchAll();

    if (!$chunks) {
        return $fail('No text was stored for that material.');
    }

    $sample   = topics_select_chunks($chunks);
    $provider = ai_provider();
    $system   = topics_system_prompt();

    /** @var array<string,array{topic:string,summary:string}> */
    $merged = [];
    $calls  = 0;
    $failed = 0;
    $lastError = null;

    foreach ($sample as $chunk) {
        $calls++;

        // The excerpt is delimited so the model can tell content from context.
        // Without this a weaker model treats the document title and the words
        // around it as topics, which the test suite caught.
        $userPrompt = "Context (do not extract topics from this line): "
                    . $resource['title'] . ", section "
                    . ((int) $chunk['chunk_index'] + 1) . " of " . count($chunks) . ".\n\n"
                    . "<excerpt>\n" . $chunk['content'] . "\n</excerpt>";

        $result = $provider->complete($system, $userPrompt, true, 900);
        ai_log($userId, $resourceId, $userPrompt, $result);

        if (!$result->ok) {
            $failed++;
            $lastError = $result->error;
            continue;
        }

        $found = topics_parse_reply($result->data ?? []);
        if ($found === []) {
            // A reply that parsed but held nothing is normal for a title page.
            continue;
        }

        foreach ($found as $topic) {
            $key = topics_normalise_key($topic['topic']);
            if ($key === '') {
                continue;
            }
            // First occurrence wins the display name; later ones only fill in a
            // summary if the first had none.
            if (!isset($merged[$key])) {
                $merged[$key] = $topic;
            } elseif ($merged[$key]['summary'] === '' && $topic['summary'] !== '') {
                $merged[$key]['summary'] = $topic['summary'];
            }
        }
    }

    // Every call failed. Report the provider's reason rather than "no topics".
    if ($calls > 0 && $failed === $calls) {
        return [
            'ok' => false, 'topics' => 0, 'new' => 0, 'calls' => $calls,
            'failed' => $failed, 'chunks' => count($chunks),
            'error' => $lastError ?? 'Every request to the AI provider failed.',
            'names' => [],
        ];
    }

    $merged = array_slice($merged, 0, AI_MAX_TOPICS_PER_RESOURCE, true);

    $inserted = 0;
    try {
        db()->beginTransaction();

        $find = db()->prepare(
            'SELECT topic_progress_id FROM topic_progress
              WHERE user_id = ? AND resource_id = ? AND topic_name = ? LIMIT 1'
        );
        $insert = db()->prepare(
            'INSERT INTO topic_progress
                (user_id, resource_id, topic_name, mastery_score, weakness_priority, scored_items)
             VALUES (?, ?, ?, 0, ?, 0)'
        );

        foreach ($merged as $topic) {
            $find->execute([$userId, $resourceId, $topic['topic']]);
            if ($find->fetch()) {
                continue;   // already tracked; leave its mastery alone
            }
            $insert->execute([$userId, $resourceId, $topic['topic'], 'none']);
            $inserted++;
        }

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('EduFlex topic insert failed: ' . $e->getMessage());
        return $fail('The topics could not be saved.');
    }

    /* Only when something was actually added. Re-running detection on a
       material whose topics are all already tracked finds nothing new, and a
       second "12 topics found" notification for the same twelve topics is
       noise. Written after the commit, and notify() never throws, so a
       detection run that cost real quota cannot be lost to a failed
       notification. */
    if ($inserted > 0) {
        $names = array_slice(
            array_map(static fn($t) => $t['topic'], $merged),
            0,
            3
        );
        notify(
            $userId,
            'topics_found',
            notifications_plural($inserted, 'new topic') . ' in '
                . mb_substr((string) $resource['title'], 0, 120),
            sprintf(
                'EduFlex read %d of %d sections and is now tracking %s. '
                . 'Each topic needs %d scored answers before it gets a mastery score.',
                $calls - $failed,
                count($chunks),
                $names === [] ? 'them' : implode(', ', $names)
                    . ($inserted > count($names) ? ' and others' : ''),
                MASTERY_MIN_ITEMS
            )
        );
    }

    return [
        'ok'     => true,
        'topics' => count($merged),
        'new'    => $inserted,
        'calls'  => $calls,
        'failed' => $failed,
        'chunks' => count($chunks),
        'error'  => $failed > 0
            ? $failed . ' of ' . $calls . ' requests failed, so coverage may be incomplete.'
            : null,
        'names'  => array_values(array_map(static fn($t) => $t['topic'], $merged)),
    ];
}

/**
 * How many topics are already recorded for a resource.
 */
function topics_count_for_resource(int $resourceId, int $userId): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM topic_progress WHERE resource_id = ? AND user_id = ?'
    );
    $stmt->execute([$resourceId, $userId]);
    return (int) $stmt->fetchColumn();
}
