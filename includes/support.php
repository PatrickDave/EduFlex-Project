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

    $stmt = db()->prepare(
        'SELECT request_id, request_type, subject, message, status, created_at
           FROM support_request
          WHERE user_id = ?
       ORDER BY created_at DESC, request_id DESC
          LIMIT ' . $limit
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
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
