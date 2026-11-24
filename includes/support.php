<?php
/**
 * EduFlex — support requests.
 *
 * Account and Access Management, Get Support. A learner writes a request, it is
 * stored against their account, and they can see what they have sent and what
 * state it is in.
 *
 * EduFlex is a non-commercial capstone prototype with no staffed helpdesk. This
 * file records requests for the project team to read; it does not send email,
 * open a ticket with anybody, or promise a reply. The copy on app/support.php
 * says so plainly, because implying a support desk exists would be a claim the
 * study cannot back up.
 *
 * Since 19 November 2026 a request is also a conversation. The team can reply
 * from the help desk, and the learner reads the reply and can answer back on
 * app/support_thread.php. The request's own message is the first message of the
 * thread; every later one is a row in support_reply. The team's side of that
 * lives in includes/admin.php. The learner's side, and the reading both sides
 * share, is here.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';

/**
 * The request types a learner may choose, and the label shown for each.
 *
 * Decided here, never taken from the POST body. request_type is a VARCHAR(20),
 * so without this list a crafted request could store anything at all in it.
 */
const SUPPORT_TYPES = [
    'question'    => 'A question about how EduFlex works',
    'bug'         => 'Something is broken',
    'ai_quality'  => 'A generated question or answer was wrong',
    'account'     => 'My account or my data',
    'suggestion'  => 'A suggestion',
];

/** Statuses the schema allows. Only the project team changes these. */
const SUPPORT_STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

/** Guards against a paste of an entire document into the message box. */
const SUPPORT_MESSAGE_MAX = 4000;
const SUPPORT_MESSAGE_MIN = 15;

/* A reply can be short: "Thanks, that fixed it" is a complete answer, and the
   fifteen-character floor above exists to make a NEW request actionable. Two
   characters still refuses an accidental click on an empty box. */
const SUPPORT_REPLY_MIN = 2;

/* -------------------------------------------------------------------------
   Writing
   ------------------------------------------------------------------------- */

/**
 * Store one support request.
 *
 * @return array{ok:bool, request_id:?int, errors:array<string,string>}
 */
function support_create(int $userId, string $type, string $subject, string $message): array
{
    $errors = [];

    $subject = trim($subject);
    $message = trim($message);

    if (!isset(SUPPORT_TYPES[$type])) {
        $errors['request_type'] = 'Choose what your request is about.';
    }

    if ($subject === '') {
        $errors['subject'] = 'Give your request a short subject.';
    } elseif (mb_strlen($subject) > 255) {
        $errors['subject'] = 'Keep the subject under 255 characters.';
    }

    if ($message === '') {
        $errors['message'] = 'Describe what you need help with.';
    } elseif (mb_strlen($message) < SUPPORT_MESSAGE_MIN) {
        $errors['message'] = 'Add a little more detail so the team can act on this.';
    } elseif (mb_strlen($message) > SUPPORT_MESSAGE_MAX) {
        $errors['message'] = 'That message is longer than '
                           . number_format(SUPPORT_MESSAGE_MAX) . ' characters.';
    }

    if ($errors) {
        return ['ok' => false, 'request_id' => null, 'errors' => $errors];
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO support_request (user_id, request_type, subject, message, status)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $type, $subject, $message, 'open']);
    } catch (Throwable $e) {
        error_log('EduFlex support_create() failed: ' . $e->getMessage());
        return [
            'ok'         => false,
            'request_id' => null,
            'errors'     => ['form' => 'Your request could not be recorded. Try again.'],
        ];
    }

    return ['ok' => true, 'request_id' => (int) db()->lastInsertId(), 'errors' => []];
}

/* -------------------------------------------------------------------------
   Reading
   ------------------------------------------------------------------------- */

/**
 * One learner's own requests, newest first.
 *
 * The user_id filter is the whole of the access control here. A support
 * request can quote anything, so another learner's rows must never be
 * reachable by guessing an id.
 *
 * @return list<array<string,mixed>>
 */
function support_list(int $userId, int $limit = 25): array
{
    $limit = (int) max(1, min($limit, 100));

    // reply_count and team_replies let the list say "2 replies from the team"
    // without a second query per row.
    $stmt = db()->prepare(
        'SELECT sr.request_id, sr.request_type, sr.subject, sr.message, sr.status,
                sr.created_at,
                (SELECT COUNT(*) FROM support_reply r
                  WHERE r.request_id = sr.request_id) AS reply_count,
                (SELECT COUNT(*) FROM support_reply r
                  WHERE r.request_id = sr.request_id AND r.from_team = 1) AS team_replies
           FROM support_request sr
          WHERE sr.user_id = ?
       ORDER BY sr.created_at DESC, sr.request_id DESC
          LIMIT ' . $limit
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * One of this learner's own requests. Null if it does not exist OR belongs to
 * somebody else, and the caller cannot tell which: a guessed id must not even
 * confirm that a request exists.
 *
 * @return array<string,mixed>|null
 */
function support_request_for_user(int $userId, int $requestId): ?array
{
    $stmt = db()->prepare(
        'SELECT request_id, user_id, request_type, subject, message, status, created_at
           FROM support_request
          WHERE request_id = ? AND user_id = ?
          LIMIT 1'
    );
    $stmt->execute([$requestId, $userId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * Every reply under one request, oldest first, with the author's name.
 *
 * NO ACCESS CHECK. This takes a request id and nothing else, so it must only be
 * called after the caller has established the right to see that request:
 * support_request_for_user() on the learner's side, admin_require() on the
 * team's. Both thread pages do exactly that before calling it.
 *
 * Ordered by reply_id rather than created_at: two replies in the same second
 * would otherwise come back in whatever order the engine likes.
 *
 * @return list<array<string,mixed>>
 */
function support_replies(int $requestId): array
{
    $stmt = db()->prepare(
        'SELECT r.reply_id, r.request_id, r.author_id, r.from_team, r.message,
                r.created_at, u.full_name AS author_name
           FROM support_reply r
      LEFT JOIN user u ON u.user_id = r.author_id
          WHERE r.request_id = ?
       ORDER BY r.reply_id ASC'
    );
    $stmt->execute([$requestId]);
    return $stmt->fetchAll();
}

/**
 * Validate a reply's text. Shared by both sides so the team and the learner
 * cannot end up with different rules.
 *
 * @return string|null the problem, or null when the text is fine
 */
function support_reply_error(string $message): ?string
{
    $message = trim($message);

    if ($message === '') {
        return 'Write a reply first.';
    }
    if (mb_strlen($message) < SUPPORT_REPLY_MIN) {
        return 'That reply is too short to send.';
    }
    if (mb_strlen($message) > SUPPORT_MESSAGE_MAX) {
        return 'That reply is longer than ' . number_format(SUPPORT_MESSAGE_MAX) . ' characters.';
    }
    return null;
}

/**
 * Store one reply. Internal: both sides call this after their own checks, and
 * nothing else should, because it trusts its arguments.
 *
 * @return int|null the new reply_id, or null when the insert failed (logged)
 */
function support_store_reply(int $requestId, int $authorId, bool $fromTeam, string $message): ?int
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO support_reply (request_id, author_id, from_team, message)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$requestId, $authorId, $fromTeam ? 1 : 0, trim($message)]);
        return (int) db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('EduFlex support_store_reply() failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * The learner answers the team on one of their own requests.
 *
 * Ownership is checked in SQL by support_request_for_user(), never assumed from
 * the session. A closed request takes no more replies; the learner is told to
 * send a new one. A resolved request is reopened, because a learner writing
 * back after "Resolved" is saying it is not, and the team should see it again.
 *
 * The team is not notified, deliberately. See SCHEMA-NOTES.md section 11: the
 * help desk shows "Needs a reply" instead.
 *
 * @return array{ok:bool, error:?string, reopened:bool}
 */
function support_learner_reply(int $userId, int $requestId, string $message): array
{
    $fail = static fn(string $m): array => ['ok' => false, 'error' => $m, 'reopened' => false];

    $request = support_request_for_user($userId, $requestId);
    if ($request === null) {
        return $fail('That request could not be found.');
    }

    if ((string) $request['status'] === 'closed') {
        return $fail('This request is closed. Send a new request if you still need help.');
    }

    $problem = support_reply_error($message);
    if ($problem !== null) {
        return $fail($problem);
    }

    if (support_store_reply($requestId, $userId, false, $message) === null) {
        return $fail('Your reply could not be sent. Try again.');
    }

    $reopened = false;
    if ((string) $request['status'] === 'resolved') {
        try {
            $stmt = db()->prepare(
                "UPDATE support_request SET status = 'open' WHERE request_id = ? AND user_id = ?"
            );
            $stmt->execute([$requestId, $userId]);
            $reopened = true;
        } catch (Throwable $e) {
            // The reply is stored, which is what matters. Log and carry on.
            error_log('EduFlex support_learner_reply() reopen failed: ' . $e->getMessage());
        }
    }

    return ['ok' => true, 'error' => null, 'reopened' => $reopened];
}

/**
 * Who wrote this message, as the person looking at the thread should see it.
 *
 * @param bool $viewerIsTeam whether the person reading is the project team
 */
function support_reply_author(array $reply, bool $viewerIsTeam, string $learnerName = ''): string
{
    $fromTeam = (int) ($reply['from_team'] ?? 0) === 1;

    if ($fromTeam) {
        $name = trim((string) ($reply['author_name'] ?? ''));
        if ($viewerIsTeam) {
            return $name !== '' ? $name : 'Project team';
        }
        return 'EduFlex project team';
    }

    return $viewerIsTeam ? ($learnerName !== '' ? $learnerName : 'Learner') : 'You';
}

/* -------------------------------------------------------------------------
   Presentation
   ------------------------------------------------------------------------- */

function support_type_label(string $type): string
{
    return SUPPORT_TYPES[$type] ?? 'Other';
}

/**
 * Reuse the mastery band colours rather than introduce a second palette.
 */
function support_status_band(string $status): string
{
    return match ($status) {
        'resolved'    => 'mastered',
        'in_progress' => 'developing',
        'open'        => 'weak',
        default       => 'none',
    };
}

function support_status_label(string $status): string
{
    return match ($status) {
        'open'        => 'Recorded',
        'in_progress' => 'Being looked at',
        'resolved'    => 'Resolved',
        'closed'      => 'Closed',
        default       => ucfirst($status),
    };
}
