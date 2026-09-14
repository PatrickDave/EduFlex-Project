<?php
/**
 * EduFlex — attempts, scoring, and the mastery write.
 *
 * This is where a learner's answers become evidence, and where that evidence
 * becomes the mastery value every screen reads. No language model is involved
 * anywhere in this file: scoring and mastery are arithmetic, they are
 * deterministic, they cost nothing, and they can be explained to a panel.
 *
 * Two rules run through everything here:
 *
 *   Never trust the browser. Correctness is decided by comparing the submitted
 *   answer against the stored one, server side. The correct answer is never
 *   sent to the page before it has been answered.
 *
 *   Never let a learner answer the same item twice in one attempt. Duplicates
 *   would double-count toward mastery.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/questions.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/../config/database.php';

/* -------------------------------------------------------------------------
   Starting an attempt
   ------------------------------------------------------------------------- */

/**
 * Begin an attempt, or resume the learner's unfinished one for this activity.
 *
 * @return array{ok:bool, attempt_id:?int, resumed:bool, error:?string}
 */
function attempt_start(int $activityId, int $userId): array
{
    $fail = static fn(string $m): array =>
        ['ok' => false, 'attempt_id' => null, 'resumed' => false, 'error' => $m];

    // The join to learning_resource is what stops one learner starting an
    // activity generated from another learner's upload.
    $stmt = db()->prepare(
        'SELECT la.activity_id, COUNT(ai.item_id) AS item_count
           FROM learning_activity la
           JOIN learning_resource lr ON lr.resource_id = la.resource_id
      LEFT JOIN activity_item ai     ON ai.activity_id = la.activity_id
          WHERE la.activity_id = ? AND lr.user_id = ?
       GROUP BY la.activity_id'
    );
    $stmt->execute([$activityId, $userId]);
    $activity = $stmt->fetch();

    if (!$activity) {
        return $fail('That practice set was not found.');
    }
    if ((int) $activity['item_count'] === 0) {
        return $fail('That practice set has no questions.');
    }

    // Resume rather than start a second attempt, so a refreshed page or a
    // closed tab does not lose recorded answers.
    $stmt = db()->prepare(
        'SELECT attempt_id FROM activity_attempt
          WHERE user_id = ? AND activity_id = ? AND completed_at IS NULL
       ORDER BY started_at DESC, attempt_id DESC LIMIT 1'
    );
    $stmt->execute([$userId, $activityId]);
    $open = $stmt->fetch();

    if ($open) {
        return ['ok' => true, 'attempt_id' => (int) $open['attempt_id'],
                'resumed' => true, 'error' => null];
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO activity_attempt (user_id, activity_id, total_items)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, $activityId, (int) $activity['item_count']]);
        return ['ok' => true, 'attempt_id' => (int) db()->lastInsertId(),
                'resumed' => false, 'error' => null];
    } catch (PDOException $e) {
        error_log('EduFlex attempt_start failed: ' . $e->getMessage());
        return $fail('The attempt could not be started.');
    }
}

/**
 * Everything the runner needs to render an attempt.
 *
 * Correct answers are NOT included. They reach the page only in the reply to a
 * submitted answer, so they cannot be read out of the source.
 *
 * @return array{ok:bool, attempt:?array, items:list<array<string,mixed>>,
 *               answered:int, error:?string}
 */
function attempt_load(int $attemptId, int $userId): array
{
    $fail = static fn(string $m): array =>
        ['ok' => false, 'attempt' => null, 'items' => [], 'answered' => 0, 'error' => $m];

    $stmt = db()->prepare(
        'SELECT aa.attempt_id, aa.activity_id, aa.score, aa.total_items,
                aa.started_at, aa.completed_at,
                la.title, la.bloom_level, la.difficulty_level, la.topic_progress_id,
                tp.topic_name
           FROM activity_attempt aa
           JOIN learning_activity la ON la.activity_id = aa.activity_id
      LEFT JOIN topic_progress tp    ON tp.topic_progress_id = la.topic_progress_id
          WHERE aa.attempt_id = ? AND aa.user_id = ? LIMIT 1'
    );
    $stmt->execute([$attemptId, $userId]);
    $attempt = $stmt->fetch();

    if (!$attempt) {
        return $fail('That attempt was not found.');
    }

    $stmt = db()->prepare(
        'SELECT ai.item_id, ai.question_text, ai.options_json,
                ar.user_answer, ar.is_correct
           FROM activity_item ai
      LEFT JOIN attempt_response ar
             ON ar.item_id = ai.item_id AND ar.attempt_id = ?
          WHERE ai.activity_id = ?
       ORDER BY ai.item_id ASC'
    );
    $stmt->execute([$attemptId, (int) $attempt['activity_id']]);

    $items    = [];
    $answered = 0;
    foreach ($stmt->fetchAll() as $row) {
        $options = json_decode((string) $row['options_json'], true);
        $isAnswered = $row['user_answer'] !== null;
        if ($isAnswered) {
            $answered++;
        }
        $items[] = [
            'item_id'       => (int) $row['item_id'],
            'question_text' => (string) $row['question_text'],
            'options'       => is_array($options) ? $options : [],
            'answered'      => $isAnswered,
            'user_answer'   => $row['user_answer'],
            'is_correct'    => $isAnswered ? (bool) $row['is_correct'] : null,
        ];
    }

    return ['ok' => true, 'attempt' => $attempt, 'items' => $items,
            'answered' => $answered, 'error' => null];
}

/* -------------------------------------------------------------------------
   Answering
   ------------------------------------------------------------------------- */

/**
 * Record one answer and say whether it was right.
 *
 * @return array{ok:bool, correct:?bool, answer:?string, explanation:?string,
 *               answered:int, total:int, error:?string}
 */
function attempt_answer(int $attemptId, int $itemId, ?string $submitted, int $userId): array
{
    $fail = static fn(string $m): array => [
        'ok' => false, 'correct' => null, 'answer' => null, 'explanation' => null,
        'answered' => 0, 'total' => 0, 'error' => $m,
    ];

    $stmt = db()->prepare(
        'SELECT aa.attempt_id, aa.activity_id, aa.completed_at, aa.total_items
           FROM activity_attempt aa
          WHERE aa.attempt_id = ? AND aa.user_id = ? LIMIT 1'
    );
    $stmt->execute([$attemptId, $userId]);
    $attempt = $stmt->fetch();

    if (!$attempt) {
        return $fail('That attempt was not found.');
    }
    if ($attempt['completed_at'] !== null) {
        return $fail('That attempt is already finished.');
    }

    // The item must belong to this attempt's activity.
    $stmt = db()->prepare(
        'SELECT item_id, correct_answer, explanation, options_json
           FROM activity_item WHERE item_id = ? AND activity_id = ? LIMIT 1'
    );
    $stmt->execute([$itemId, (int) $attempt['activity_id']]);
    $item = $stmt->fetch();

    if (!$item) {
        return $fail('That question is not part of this set.');
    }

    $stmt = db()->prepare(
        'SELECT response_id FROM attempt_response
          WHERE attempt_id = ? AND item_id = ? LIMIT 1'
    );
    $stmt->execute([$attemptId, $itemId]);
    if ($stmt->fetch()) {
        return $fail('That question has already been answered.');
    }

    // Correctness is decided here, from the stored answer. A skipped question
    // records a null answer and counts as incorrect, which is the honest
    // treatment: the learner did not demonstrate the knowledge.
    $answer  = (string) $item['correct_answer'];
    $correct = $submitted !== null
        && mb_strtolower(trim($submitted)) === mb_strtolower(trim($answer));

    try {
        $stmt = db()->prepare(
            'INSERT INTO attempt_response (attempt_id, item_id, user_answer, is_correct)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $attemptId,
            $itemId,
            $submitted === null ? null : mb_substr($submitted, 0, 2000),
            $correct ? 1 : 0,
        ]);
    } catch (PDOException $e) {
        error_log('EduFlex attempt_answer failed: ' . $e->getMessage());
        return $fail('That answer could not be recorded.');
    }

    $stmt = db()->prepare('SELECT COUNT(*) FROM attempt_response WHERE attempt_id = ?');
    $stmt->execute([$attemptId]);

    return [
        'ok'          => true,
        'correct'     => $correct,
        'answer'      => $answer,
        'explanation' => $item['explanation'] !== null ? (string) $item['explanation'] : null,
        'answered'    => (int) $stmt->fetchColumn(),
        'total'       => (int) $attempt['total_items'],
        'error'       => null,
    ];
}

/* -------------------------------------------------------------------------
   Finishing
   ------------------------------------------------------------------------- */

/**
 * Score the attempt, then update the topic's mastery.
 *
 * @return array{ok:bool, score:?float, correct:int, total:int,
 *               mastery:?array, error:?string}
 */
function attempt_finish(int $attemptId, int $userId): array
{
    $fail = static fn(string $m): array => [
        'ok' => false, 'score' => null, 'correct' => 0, 'total' => 0,
        'mastery' => null, 'error' => $m,
    ];

    $stmt = db()->prepare(
        'SELECT aa.attempt_id, aa.activity_id, aa.total_items, aa.completed_at,
                la.topic_progress_id
           FROM activity_attempt aa
           JOIN learning_activity la ON la.activity_id = aa.activity_id
          WHERE aa.attempt_id = ? AND aa.user_id = ? LIMIT 1'
    );
    $stmt->execute([$attemptId, $userId]);
    $attempt = $stmt->fetch();

    if (!$attempt) {
        return $fail('That attempt was not found.');
    }
    if ($attempt['completed_at'] !== null) {
        return $fail('That attempt is already finished.');
    }

    $stmt = db()->prepare(
        'SELECT COUNT(*) AS answered, COALESCE(SUM(is_correct), 0) AS correct
           FROM attempt_response WHERE attempt_id = ?'
    );
    $stmt->execute([$attemptId]);
    $tally = $stmt->fetch() ?: ['answered' => 0, 'correct' => 0];

    $answered = (int) $tally['answered'];
    if ($answered === 0) {
        return $fail('Answer at least one question before finishing.');
    }

    // Unanswered questions count against the score. Otherwise skipping every
    // hard question would produce a perfect result.
    $total   = max(1, (int) $attempt['total_items']);
    $correct = (int) $tally['correct'];
    $score   = round(($correct / $total) * 100, 2);

    try {
        db()->beginTransaction();

        $stmt = db()->prepare(
            'UPDATE activity_attempt
                SET score = ?, completed_at = CURRENT_TIMESTAMP
              WHERE attempt_id = ? AND completed_at IS NULL'
        );
        $stmt->execute([$score, $attemptId]);

        $mastery = null;
        if ($attempt['topic_progress_id'] !== null) {
            $mastery = mastery_recalculate((int) $attempt['topic_progress_id'], $userId);
        }

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('EduFlex attempt_finish failed: ' . $e->getMessage());
        return $fail('The attempt could not be scored.');
    }

    // Refreshing the recommendation is not part of scoring, so a failure here
    // must not undo a recorded result.
    if ($attempt['topic_progress_id'] !== null) {
        recommendation_refresh($userId);
    }

    return [
        'ok'      => true,
        'score'   => $score,
        'correct' => $correct,
        'total'   => $total,
        'mastery' => $mastery,
        'error'   => null,
    ];
}

/* -------------------------------------------------------------------------
   The mastery write

   This is the calculation the whole study rests on. It is documented in
   README section 4 and in Chapter III; keep all three in step.
   ------------------------------------------------------------------------- */

/**
 * Recalculate one topic's mastery from every answer ever recorded for it.
 *
 *     M = ( SUM(w_i * c_i) / SUM(w_i) ) * 100
 *     w_i = bloom_weight * recency_weight
 *     recency_weight = 1 / (1 + 0.15k), k = how many answers ago
 *
 * Recomputed from the full history rather than adjusted incrementally, so a
 * deleted attempt or a corrected answer can never leave the value drifting.
 *
 * @return array{mastery:float, scored_items:int, band:string, previous:float,
 *               previous_band:string, delta:float}
 */
function mastery_recalculate(int $topicProgressId, int $userId): array
{
    // weakness_priority holds the band this function last wrote, so reading it
    // here is how the transition into "mastered" is detected further down
    // without recomputing anything.
    $stmt = db()->prepare('SELECT mastery_score, weakness_priority, topic_name
                             FROM topic_progress
                            WHERE topic_progress_id = ? AND user_id = ?');
    $stmt->execute([$topicProgressId, $userId]);
    $before = $stmt->fetch() ?: [];

    $previous     = (float) ($before['mastery_score'] ?? 0.0);
    $previousBand = (string) ($before['weakness_priority'] ?? 'none');
    $topicName    = (string) ($before['topic_name'] ?? '');

    // Newest first, because the recency weight is defined by position.
    $stmt = db()->prepare(
        'SELECT ar.is_correct, la.bloom_level
           FROM attempt_response ar
           JOIN activity_attempt aa  ON aa.attempt_id = ar.attempt_id
           JOIN learning_activity la ON la.activity_id = aa.activity_id
          WHERE aa.user_id = ?
            AND la.topic_progress_id = ?
       ORDER BY ar.answered_at DESC, ar.response_id DESC'
    );
    $stmt->execute([$userId, $topicProgressId]);
    $responses = $stmt->fetchAll();

    $weighted = 0.0;
    $weights  = 0.0;

    foreach ($responses as $k => $row) {
        $bloomWeight   = BLOOM_WEIGHTS[(string) $row['bloom_level']] ?? 1.0;
        $recencyWeight = 1 / (1 + (MASTERY_RECENCY_DECAY * $k));
        $w = $bloomWeight * $recencyWeight;

        $weights  += $w;
        $weighted += $w * ((int) $row['is_correct'] === 1 ? 1 : 0);
    }

    $scoredItems = count($responses);
    $mastery     = $weights > 0 ? round(($weighted / $weights) * 100, 2) : 0.0;
    $band        = mastery_band($mastery, $scoredItems);

    $stmt = db()->prepare(
        'UPDATE topic_progress
            SET mastery_score = ?, scored_items = ?, weakness_priority = ?
          WHERE topic_progress_id = ? AND user_id = ?'
    );
    $stmt->execute([$mastery, $scoredItems, $band, $topicProgressId, $userId]);

    /* The transition into "mastered", and only the transition. This function
       runs after every finished attempt, so notifying on each recalculation
       would bury the learner in "you have mastered X" for a topic they
       mastered last week. A band that drops out of mastered and climbs back
       notifies again, which is correct: it is news the second time too. */
    if ($band === 'mastered' && $previousBand !== 'mastered' && $topicName !== '') {
        notify(
            $userId,
            'topic_mastered',
            'You have mastered ' . mb_substr($topicName, 0, 120),
            sprintf(
                '%s is at %d%% across %s. That is the mastered band, which starts at %d%%. '
                . 'EduFlex will move its attention to a weaker topic.',
                $topicName,
                (int) round($mastery),
                notifications_plural($scoredItems, 'scored answer'),
                (int) MASTERY_MASTERED_AT
            )
        );
    }

    return [
        'mastery'       => $mastery,
        'scored_items'  => $scoredItems,
        'band'          => $band,
        'previous'      => $previous,
        'previous_band' => $previousBand,
        'delta'         => round($mastery - $previous, 2),
    ];
}

/* -------------------------------------------------------------------------
   Recommendation

   Arithmetic, not a model call. Being able to say "the recommendation is
   deterministic, here is the rule" is worth more than any generated sentence.
   ------------------------------------------------------------------------- */

/**
 * Point the learner at their weakest qualifying topic.
 *
 * Supersedes any earlier open recommendation, so exactly one is live at a time.
 *
 * @return array{ok:bool, recommendation_id:?int, topic:?string, reason:?string}
 */
function recommendation_refresh(int $userId): array
{
    $none = ['ok' => false, 'recommendation_id' => null, 'topic' => null, 'reason' => null];

    try {
        $stmt = db()->prepare(
            'SELECT tp.topic_progress_id, tp.topic_name, tp.mastery_score, tp.scored_items
               FROM topic_progress tp
              WHERE tp.user_id = ? AND tp.scored_items >= ?
           ORDER BY tp.mastery_score ASC, tp.topic_progress_id ASC
              LIMIT 1'
        );
        $stmt->execute([$userId, MASTERY_MIN_ITEMS]);
        $weakest = $stmt->fetch();

        // Nothing has enough evidence yet. Say nothing rather than guess.
        if (!$weakest) {
            return $none;
        }

        $topicId = (int) $weakest['topic_progress_id'];
        $mastery = (float) $weakest['mastery_score'];
        $items   = (int) $weakest['scored_items'];
        $band    = mastery_band($mastery, $items);
        $level   = questions_next_bloom_level($topicId, $userId);

        // Everything is already mastered. Retire the open recommendation
        // instead of nagging about a topic that needs no work.
        if ($band === 'mastered') {
            db()->prepare("UPDATE recommendation SET status = 'completed'
                            WHERE user_id = ? AND status IN ('new','viewed')")
                ->execute([$userId]);
            return $none;
        }

        $reason = sprintf(
            '%s is at %d%% mastery across %d scored answer%s, the lowest of your '
            . 'active topics. That is the %s band, so EduFlex will target it next.',
            $weakest['topic_name'],
            (int) round($mastery),
            $items,
            $items === 1 ? '' : 's',
            $band
        );

        /* What the learner was last pointed at. Read before the supersede, so
           the notification below can tell a genuinely new recommendation from
           the same one restated. */
        $find = db()->prepare("SELECT topic_progress_id FROM recommendation
                                WHERE user_id = ? AND status IN ('new','viewed')
                             ORDER BY recommendation_id DESC LIMIT 1");
        $find->execute([$userId]);
        $previousTopicId = $find->fetchColumn();
        $previousTopicId = $previousTopicId === false ? null : (int) $previousTopicId;

        db()->prepare("UPDATE recommendation SET status = 'superseded'
                        WHERE user_id = ? AND status IN ('new','viewed')")
            ->execute([$userId]);

        $stmt = db()->prepare(
            'INSERT INTO recommendation
                (user_id, topic_progress_id, recommended_level, recommended_activity,
                 reason, status)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $topicId,
            $level,
            mb_substr($weakest['topic_name'] . ': guided practice set', 0, 255),
            $reason,
            'new',
        ]);

        $recommendationId = (int) db()->lastInsertId();

        /* Only when the target has actually moved. This function runs after
           every finished attempt and writes a fresh row each time, so
           notifying on every insert would produce one "practise X next" per
           attempt for the same X. The same rule as topic_mastered: tell the
           learner what changed, not what was recalculated. */
        if ($previousTopicId !== $topicId) {
            notify(
                $userId,
                'recommendation',
                'Practise ' . mb_substr((string) $weakest['topic_name'], 0, 120) . ' next',
                $reason . ' The next set will be at the ' . $level . ' level.'
            );
        }

        return [
            'ok'                => true,
            'recommendation_id' => $recommendationId,
            'topic'             => (string) $weakest['topic_name'],
            'reason'            => $reason,
        ];
    } catch (Throwable $e) {
        error_log('EduFlex recommendation_refresh failed: ' . $e->getMessage());
        return $none;
    }
}

/* -------------------------------------------------------------------------
   Review
   ------------------------------------------------------------------------- */

/**
 * A finished attempt with every question, the learner's answer, and why the
 * right answer is right. Correct answers are safe to include here: the attempt
 * is over.
 *
 * @return list<array<string,mixed>>
 */
function attempt_review(int $attemptId, int $userId): array
{
    $stmt = db()->prepare(
        'SELECT ai.item_id, ai.question_text, ai.options_json,
                ai.correct_answer, ai.explanation,
                ar.user_answer, ar.is_correct
           FROM activity_item ai
           JOIN activity_attempt aa ON aa.activity_id = ai.activity_id
      LEFT JOIN attempt_response ar
             ON ar.item_id = ai.item_id AND ar.attempt_id = aa.attempt_id
          WHERE aa.attempt_id = ? AND aa.user_id = ?
       ORDER BY ai.item_id ASC'
    );
    $stmt->execute([$attemptId, $userId]);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $options = json_decode((string) $row['options_json'], true);
        $out[] = [
            'item_id'        => (int) $row['item_id'],
            'question_text'  => (string) $row['question_text'],
            'options'        => is_array($options) ? $options : [],
            'correct_answer' => (string) $row['correct_answer'],
            'explanation'    => $row['explanation'],
            'user_answer'    => $row['user_answer'],
            'answered'       => $row['user_answer'] !== null,
            'is_correct'     => $row['user_answer'] !== null ? (bool) $row['is_correct'] : null,
        ];
    }
    return $out;
}

/* -------------------------------------------------------------------------
   Acting on the recommendation

   A recommendation that only names a topic asks the learner to go and find it.
   This turns it into one action: take the recommended topic, reuse a stored set
   the learner has not attempted or write a new one, and open it.
   ------------------------------------------------------------------------- */

/**
 * The topic the learner should practise next, and why.
 *
 * Falls back sensibly so the button is never dead: an open recommendation
 * first, then the weakest topic that has enough answers to judge, then any
 * topic at all. Only a learner with no detected topics gets nothing.
 *
 * @return array{ok:bool, topic_progress_id:?int, topic_name:?string,
 *               reason:?string, source:string, error:?string}
 */
function practice_next_topic(int $userId): array
{
    $fail = static fn(string $m): array => [
        'ok' => false, 'topic_progress_id' => null, 'topic_name' => null,
        'reason' => null, 'source' => 'none', 'error' => $m,
    ];

    $stmt = db()->prepare(
        "SELECT r.recommendation_id, r.topic_progress_id, r.reason, tp.topic_name
           FROM recommendation r
           JOIN topic_progress tp ON tp.topic_progress_id = r.topic_progress_id
          WHERE r.user_id = ? AND r.status IN ('new','viewed')
       ORDER BY r.created_at DESC, r.recommendation_id DESC LIMIT 1"
    );
    $stmt->execute([$userId]);
    $reco = $stmt->fetch();

    if ($reco) {
        return [
            'ok'                => true,
            'topic_progress_id' => (int) $reco['topic_progress_id'],
            'topic_name'        => (string) $reco['topic_name'],
            'reason'            => (string) $reco['reason'],
            'source'            => 'recommendation',
            'error'             => null,
        ];
    }

    /* No recommendation yet, which is the normal state before five answers
       exist. Weakest first is still the right default, and saying that it is a
       default rather than a recommendation keeps the interface honest. */
    $stmt = db()->prepare(
        'SELECT topic_progress_id, topic_name, mastery_score, scored_items
           FROM topic_progress
          WHERE user_id = ?
       ORDER BY scored_items >= ' . MASTERY_MIN_ITEMS . ' DESC,
                mastery_score ASC, topic_progress_id ASC
          LIMIT 1'
    );
    $stmt->execute([$userId]);
    $topic = $stmt->fetch();

    if (!$topic) {
        return $fail('No topics have been detected yet. Upload a document and '
            . 'run topic detection first.');
    }

    $enough = (int) $topic['scored_items'] >= MASTERY_MIN_ITEMS;

    return [
        'ok'                => true,
        'topic_progress_id' => (int) $topic['topic_progress_id'],
        'topic_name'        => (string) $topic['topic_name'],
        'reason'            => $enough
            ? 'Your weakest topic at ' . round((float) $topic['mastery_score']) . '%.'
            : 'Starting here because EduFlex does not have enough answers yet to '
              . 'tell which topic is weakest.',
        'source'            => $enough ? 'weakest' : 'default',
        'error'             => null,
    ];
}

/**
 * Open a practice attempt on a topic, writing a new set only if needed.
 *
 * Reusing an unattempted stored set matters: generation is the one part of the
 * system that costs a provider request, so a button a learner presses often
 * must not spend one every time.
 *
 * @return array{ok:bool, attempt_id:?int, activity_id:?int, generated:bool,
 *               topic_name:?string, error:?string}
 */
function practice_start_topic(int $topicProgressId, int $userId): array
{
    $fail = static fn(string $m): array => [
        'ok' => false, 'attempt_id' => null, 'activity_id' => null,
        'generated' => false, 'topic_name' => null, 'error' => $m,
    ];

    $stmt = db()->prepare(
        'SELECT topic_progress_id, topic_name FROM topic_progress
          WHERE topic_progress_id = ? AND user_id = ? LIMIT 1'
    );
    $stmt->execute([$topicProgressId, $userId]);
    $topic = $stmt->fetch();

    if (!$topic) {
        return $fail('That topic was not found.');
    }

    /* The recommendation has been acted on the moment an attempt opens on its
       topic, whether that attempt was resumed, reused or newly written. Defined
       once here so no success path can forget it; marking it is bookkeeping, so
       a failure must never stop a learner practising. */
    $accept = static function () use ($userId, $topicProgressId): void {
        try {
            db()->prepare(
                "UPDATE recommendation SET status = 'accepted'
                  WHERE user_id = ? AND topic_progress_id = ?
                    AND status IN ('new','viewed')"
            )->execute([$userId, $topicProgressId]);
        } catch (PDOException $e) {
            error_log('EduFlex recommendation accept failed: ' . $e->getMessage());
        }
    };

    /* An attempt on this topic that was never finished is where the learner
       left off, so go back to it. Without this check, pressing the button a
       second time skipped past the open attempt, found no free set, and paid
       for a new one. */
    $stmt = db()->prepare(
        'SELECT aa.activity_id
           FROM activity_attempt aa
           JOIN learning_activity la ON la.activity_id = aa.activity_id
          WHERE aa.user_id = ? AND aa.completed_at IS NULL
            AND la.topic_progress_id = ?
       ORDER BY aa.started_at DESC, aa.attempt_id DESC LIMIT 1'
    );
    $stmt->execute([$userId, $topicProgressId]);
    $openAttempt = $stmt->fetch();

    if ($openAttempt) {
        $start = attempt_start((int) $openAttempt['activity_id'], $userId);
        if ($start['ok']) {
            $accept();
            return [
                'ok'          => true,
                'attempt_id'  => (int) $start['attempt_id'],
                'activity_id' => (int) $openAttempt['activity_id'],
                'generated'   => false,
                'topic_name'  => (string) $topic['topic_name'],
                'error'       => null,
            ];
        }
    }

    // A set the learner has never attempted, oldest first so stored sets get
    // used up rather than one being generated while others go untouched.
    $stmt = db()->prepare(
        'SELECT la.activity_id
           FROM learning_activity la
           JOIN learning_resource lr ON lr.resource_id = la.resource_id
          WHERE la.topic_progress_id = ? AND lr.user_id = ?
            AND NOT EXISTS (SELECT 1 FROM activity_attempt aa
                             WHERE aa.activity_id = la.activity_id
                               AND aa.user_id = ?)
       ORDER BY la.generated_at ASC, la.activity_id ASC LIMIT 1'
    );
    $stmt->execute([$topicProgressId, $userId, $userId]);
    $unused = $stmt->fetch();

    $generated = false;

    if ($unused) {
        $activityId = (int) $unused['activity_id'];
    } else {
        $result = questions_generate($topicProgressId, $userId);
        if (!$result['ok'] || $result['activity_id'] === null) {
            return $fail((string) ($result['error'] ?? 'A new set could not be written.'));
        }
        $activityId = (int) $result['activity_id'];
        $generated  = true;
    }

    $start = attempt_start($activityId, $userId);
    if (!$start['ok']) {
        return $fail((string) $start['error']);
    }

    $accept();

    return [
        'ok'         => true,
        'attempt_id' => (int) $start['attempt_id'],
        'activity_id' => $activityId,
        'generated'  => $generated,
        'topic_name' => (string) $topic['topic_name'],
        'error'      => null,
    ];
}
