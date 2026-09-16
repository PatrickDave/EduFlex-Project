<?php
/**
 * EduFlex — mock examinations.
 *
 * Learning Activity and Assessment, sub-module 2: Generate Mock Examinations.
 *
 * Chapter III defines this as "the process where the system creates practice
 * examinations based on uploaded learning materials to help learners assess
 * their understanding and prepare for academic assessments". The words that
 * shaped the design are "examinations" and "prepare for academic assessments":
 * an exam covers more ground than one practice set, so a mock examination here
 * spans several topics rather than drilling one.
 *
 * HOW IT KEEPS QUOTA DISCIPLINE. Generation is one provider call per set of
 * eight, so a 20-question exam written from scratch would cost three calls
 * every time. It does not work that way. The exam is ASSEMBLED: it takes
 * questions the learner has never answered from sets that already exist, and
 * calls the provider only for the shortfall. A learner who has practised for a
 * while gets an exam for nothing. See README section 6h.
 *
 * WHAT IT DOES TO MASTERY. Exam answers count exactly like practice answers,
 * at the same Bloom and recency weighting. That is why activity_item carries
 * its own topic_progress_id and bloom_level: the exam activity spans several
 * topics and levels, so each item has to say which topic it is evidence for.
 * mastery_recalculate() reads the item's values and falls back to the
 * activity's, so practice sets are unaffected.
 */

declare(strict_types=1);

require_once __DIR__ . '/questions.php';
require_once __DIR__ . '/attempts.php';
require_once __DIR__ . '/../config/database.php';

/** Questions in a mock examination. */
const EXAM_QUESTION_COUNT = 20;

/** How many topics one examination draws on. */
const EXAM_MAX_TOPICS = 3;

/**
 * The fewest topics an examination is worth building from.
 *
 * One topic is a practice set with a longer name. A learner with a single topic
 * is told to practise instead, which is the honest answer.
 */
const EXAM_MIN_TOPICS = 2;

/** The activity_type that marks an examination, as against 'practice_set'. */
const EXAM_ACTIVITY_TYPE = 'mock_exam';

/* -------------------------------------------------------------------------
   Choosing what the examination covers
   ------------------------------------------------------------------------- */

/**
 * The topics an examination should cover, weakest first.
 *
 * Weakest first for the same reason the recommendation is weakest first: the
 * point of the exercise is to find out what the learner does not know. Topics
 * with no stored passages are excluded, because nothing could be generated for
 * them if the stored questions ran out.
 *
 * @return list<array<string,mixed>>
 */
function exam_select_topics(int $userId, int $limit = EXAM_MAX_TOPICS): array
{
    $limit = (int) max(1, min($limit, 10));

    $stmt = db()->prepare(
        'SELECT tp.topic_progress_id, tp.topic_name, tp.mastery_score, tp.scored_items,
                tp.resource_id
           FROM topic_progress tp
           JOIN learning_resource lr ON lr.resource_id = tp.resource_id
          WHERE tp.user_id = ?
            AND lr.processing_status = ?
            AND EXISTS (SELECT 1 FROM resource_chunk rc
                         WHERE rc.resource_id = tp.resource_id)
       ORDER BY tp.mastery_score ASC, tp.topic_progress_id ASC
          LIMIT ' . $limit
    );
    $stmt->execute([$userId, 'processed']);

    return $stmt->fetchAll();
}

/**
 * Split a question count across topics as evenly as it will go.
 *
 * 20 over 3 topics is 7, 7, 6 rather than 6, 6, 6 and two questions lost. The
 * remainder goes to the earliest topics, which are the weakest ones.
 *
 * @return list<int>
 */
function exam_share_questions(int $total, int $topics): array
{
    if ($topics <= 0 || $total <= 0) {
        return [];
    }

    $base      = intdiv($total, $topics);
    $remainder = $total % $topics;

    $shares = [];
    for ($i = 0; $i < $topics; $i++) {
        $shares[] = $base + ($i < $remainder ? 1 : 0);
    }

    return $shares;
}

/* -------------------------------------------------------------------------
   Finding questions that already exist
   ------------------------------------------------------------------------- */

/**
 * Questions for one topic that this learner has never been asked.
 *
 * Never-asked matters twice over: a question the learner has already seen tests
 * recall of the answer rather than of the material, and serving it again would
 * put the same question into mastery twice under a different attempt.
 *
 * Only practice sets are drawn from. Items already inside another examination
 * are left alone so two exams do not end up as copies of each other.
 *
 * THE EXCLUSION MATCHES ON QUESTION TEXT, NOT ITEM ID, and that is deliberate.
 * Building an examination copies the chosen questions into the exam's own
 * activity, because an item belongs to exactly one activity. Answering the copy
 * therefore leaves the original untouched, and an id-based check happily served
 * the same question in the next examination. The test suite caught it. Matching
 * the text catches the original and every copy of it at once, which is what
 * "already asked" actually means. Identical question text is a duplicate by
 * definition here: questions_validate() already refuses one inside a set.
 *
 * @return list<array<string,mixed>>
 */
function exam_unseen_items(int $userId, int $topicProgressId, int $wanted): array
{
    if ($wanted <= 0) {
        return [];
    }
    $wanted = (int) min($wanted, 100);

    $stmt = db()->prepare(
        'SELECT ai.item_id, ai.question_text, ai.item_type, ai.options_json,
                ai.correct_answer, ai.explanation, la.bloom_level
           FROM activity_item ai
           JOIN learning_activity la ON la.activity_id = ai.activity_id
          WHERE la.topic_progress_id = ?
            AND la.activity_type = ?
            AND NOT EXISTS (
                  SELECT 1
                    FROM attempt_response ar
                    JOIN activity_attempt aa   ON aa.attempt_id = ar.attempt_id
                    JOIN activity_item   asked ON asked.item_id = ar.item_id
                   WHERE aa.user_id = ?
                     AND asked.question_text = ai.question_text
                )
       ORDER BY la.generated_at DESC, ai.item_id ASC
          LIMIT ' . $wanted
    );
    $stmt->execute([$topicProgressId, 'practice_set', $userId]);

    return $stmt->fetchAll();
}

/* -------------------------------------------------------------------------
   Building one
   ------------------------------------------------------------------------- */

/**
 * Assemble a mock examination and start an attempt on it.
 *
 * @return array{ok:bool, attempt_id:?int, activity_id:?int, items:int,
 *               topics:list<string>, generated:int, reused:int, error:?string}
 */
function exam_start(int $userId, int $questionCount = EXAM_QUESTION_COUNT): array
{
    $fail = static fn(string $m): array => [
        'ok' => false, 'attempt_id' => null, 'activity_id' => null, 'items' => 0,
        'topics' => [], 'generated' => 0, 'reused' => 0, 'error' => $m,
    ];

    $questionCount = (int) max(4, min($questionCount, 60));

    $topics = exam_select_topics($userId);

    if (count($topics) < EXAM_MIN_TOPICS) {
        return $fail(
            'A mock examination covers several topics, and you have '
            . (count($topics) === 1 ? 'only one so far' : 'none yet') . '. '
            . 'Upload another material, or run topic detection on one you have, '
            . 'and the option will appear.'
        );
    }

    $shares    = exam_share_questions($questionCount, count($topics));
    $chosen    = [];
    $reused    = 0;
    $generated = 0;
    $names     = [];

    foreach ($topics as $i => $topic) {
        $topicId = (int) $topic['topic_progress_id'];
        $need    = $shares[$i] ?? 0;
        $names[] = (string) $topic['topic_name'];

        $items = exam_unseen_items($userId, $topicId, $need);
        $reused += count($items);

        /* Short of questions for this topic, so buy some. One call yields a set
           of eight, which usually covers the shortfall in a single go. A
           failure here is not fatal: the exam is assembled from whatever the
           other topics provided, and the count is reported honestly. */
        if (count($items) < $need) {
            $result = questions_generate($topicId, $userId);
            if ($result['ok']) {
                $generated++;
                $more = exam_unseen_items($userId, $topicId, $need - count($items));
                $items = array_merge($items, $more);
            }
        }

        foreach ($items as $item) {
            $item['topic_progress_id'] = $topicId;
            $chosen[] = $item;
        }
    }

    if (count($chosen) < EXAM_MIN_TOPICS * 2) {
        return $fail(
            'There were not enough questions to build an examination. '
            . 'Practise a little first, or add more material.'
        );
    }

    /* The activity needs one bloom_level because the column is NOT NULL, but an
       exam genuinely spans levels. The commonest level among the chosen items
       is stored as a summary; every item keeps its own, and that is what
       mastery actually reads. */
    $levels = array_filter(array_map(
        static fn(array $i): string => (string) ($i['bloom_level'] ?? ''),
        $chosen
    ));
    $counts = array_count_values($levels);
    arsort($counts);
    $headline = (string) (array_key_first($counts) ?? BLOOM_ORDER[0]);

    try {
        db()->beginTransaction();

        $stmt = db()->prepare(
            'INSERT INTO learning_activity
                (resource_id, topic_progress_id, activity_type, title,
                 bloom_level, difficulty_level)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int) $topics[0]['resource_id'],
            /* NULL on purpose. The activity belongs to no single topic, which
               is exactly why the items carry their own. */
            null,
            EXAM_ACTIVITY_TYPE,
            mb_substr('Mock examination: ' . implode(', ', $names), 0, 255),
            $headline,
            questions_difficulty_for_bloom($headline),
        ]);
        $activityId = (int) db()->lastInsertId();

        $insert = db()->prepare(
            'INSERT INTO activity_item
                (activity_id, question_text, item_type, options_json,
                 correct_answer, explanation, topic_progress_id, bloom_level)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($chosen as $item) {
            $insert->execute([
                $activityId,
                (string) $item['question_text'],
                (string) ($item['item_type'] ?? 'multiple_choice'),
                $item['options_json'],
                (string) $item['correct_answer'],
                $item['explanation'],
                (int) $item['topic_progress_id'],
                (string) ($item['bloom_level'] ?? $headline),
            ]);
        }

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('EduFlex exam assembly failed: ' . $e->getMessage());
        return $fail('The examination could not be built.');
    }

    $started = attempt_start($activityId, $userId);
    if (!$started['ok']) {
        return $fail((string) $started['error']);
    }

    return [
        'ok'          => true,
        'attempt_id'  => (int) $started['attempt_id'],
        'activity_id' => $activityId,
        'items'       => count($chosen),
        'topics'      => $names,
        'generated'   => $generated,
        'reused'      => $reused,
        'error'       => null,
    ];
}

/* -------------------------------------------------------------------------
   Reading
   ------------------------------------------------------------------------- */

/** Is this activity a mock examination rather than a practice set? */
function exam_is_exam(?string $activityType): bool
{
    return $activityType === EXAM_ACTIVITY_TYPE;
}

/**
 * Whether a learner has enough topics for an examination to be offered.
 *
 * The Practice screen asks this before showing the button, so a learner is
 * never offered something that would refuse them.
 */
function exam_is_available(int $userId): bool
{
    return count(exam_select_topics($userId)) >= EXAM_MIN_TOPICS;
}

/**
 * A learner's past examinations, newest first.
 *
 * @return list<array<string,mixed>>
 */
function exam_list(int $userId, int $limit = 10): array
{
    $limit = (int) max(1, min($limit, 50));

    $stmt = db()->prepare(
        'SELECT la.activity_id, la.title, la.generated_at,
                aa.attempt_id, aa.score, aa.total_items, aa.completed_at
           FROM learning_activity la
           JOIN learning_resource lr ON lr.resource_id = la.resource_id
      LEFT JOIN activity_attempt aa
             ON aa.activity_id = la.activity_id AND aa.user_id = ?
          WHERE lr.user_id = ? AND la.activity_type = ?
       ORDER BY la.generated_at DESC, la.activity_id DESC
          LIMIT ' . $limit
    );
    $stmt->execute([$userId, $userId, EXAM_ACTIVITY_TYPE]);

    return $stmt->fetchAll();
}

/**
 * How one finished examination broke down by topic.
 *
 * The point of an exam is finding out where you are weak, so the result screen
 * needs the per-topic split rather than one number.
 *
 * @return list<array{topic:string, answered:int, correct:int}>
 */
function exam_topic_breakdown(int $attemptId, int $userId): array
{
    $stmt = db()->prepare(
        'SELECT tp.topic_name,
                COUNT(ar.response_id) AS answered,
                COALESCE(SUM(ar.is_correct), 0) AS correct
           FROM attempt_response ar
           JOIN activity_attempt aa ON aa.attempt_id = ar.attempt_id
           JOIN activity_item ai    ON ai.item_id = ar.item_id
           JOIN topic_progress tp   ON tp.topic_progress_id = ai.topic_progress_id
          WHERE ar.attempt_id = ? AND aa.user_id = ?
       GROUP BY tp.topic_progress_id, tp.topic_name
       ORDER BY tp.topic_name ASC'
    );
    $stmt->execute([$attemptId, $userId]);

    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'topic'    => (string) $row['topic_name'],
            'answered' => (int) $row['answered'],
            'correct'  => (int) $row['correct'],
        ];
    }

    return $rows;
}
