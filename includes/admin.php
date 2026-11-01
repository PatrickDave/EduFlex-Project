<?php
/**
 * EduFlex — the support queue, seen from the project team's side.
 *
 * Module 7 recorded a learner's request and then nobody could read it. The
 * status column was written as 'open' at creation and no code anywhere changed
 * it, so "View Request Status" showed "Recorded" forever and a submitted
 * request went nowhere visible. The adviser asked for this at the second
 * consultation on 27 October 2026.
 *
 * This is the one part of EduFlex that deliberately reads across learners.
 * Everywhere else, every query filters by user_id in SQL and ownership is never
 * assumed from the session. Here the whole point is to see other people's
 * requests, so the guard moves from the query to the door: admin_require() runs
 * before a single byte of output, and admin_is_admin() is the only thing that
 * opens it.
 *
 * What an admin can see is deliberately narrow: support requests, and the name
 * and email of whoever sent one. Not their uploads, not their conversations,
 * not their mastery. privacy.php already tells participants the project team
 * can read the database; this keeps what the interface exposes to the smallest
 * set that answers the request.
 *
 * is_admin is set by hand in phpMyAdmin:
 *
 *     UPDATE user SET is_admin = 1 WHERE email = 'you@example.com';
 *
 * Nothing in the interface grants it. There is no promote button, no admin
 * registration, no "first user becomes admin". That is not an omission: a flag
 * no code can write is a flag no bug can hand to a learner.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/support.php';

/** Statuses an admin may move a request to, and what the move is called. */
const ADMIN_STATUS_ACTIONS = [
    'open'        => 'Reopen',
    'in_progress' => 'Start looking at it',
    'resolved'    => 'Mark resolved',
];

/**
 * Which request types are feedback rather than a call for help.
 *
 * These are Appendix R 7.4 "Report Incorrect AI Output" and 7.5 "Provide System
 * Feedback". They arrive through the same form and the same table as every
 * other request, because that is how a learner thinks of them, but the team
 * reads them for a different reason: one is a bug queue, the other is evidence
 * for Chapter IV about the quality of generated output.
 */
const ADMIN_FEEDBACK_TYPES = ['ai_quality', 'suggestion'];

/* -------------------------------------------------------------------------
   The door
   ------------------------------------------------------------------------- */

/*
 * admin_is_admin() is NOT here. It lives in includes/auth.php.
 *
 * It was here first, and the sidebar could never show the Support Queue link:
 * only app/admin.php requires this file, so on the other eleven pages the
 * function did not exist and the guarded call silently skipped. Asking whether
 * a user row carries a flag is a question about a user, and auth.php is loaded
 * by every page, so that is where it belongs. Everything below is about the
 * queue itself and stays here.
 */

/**
 * Gate an admin page. Call it BEFORE any output.
 *
 * Signed out goes to the login screen, which is what every other page does.
 * Signed in but not an admin goes to the dashboard with a flash, rather than a
 * 404: the page's existence is not a secret, the data behind it is, and a
 * learner who mistypes a URL deserves a sentence rather than a dead end.
 */
function admin_require(): void
{
    auth_require_login();

    if (!admin_is_admin(auth_user())) {
        flash_set('error', 'That area is for the project team.');
        redirect('dashboard.php');
    }
}

/* -------------------------------------------------------------------------
   Reading the queue
   ------------------------------------------------------------------------- */

/**
 * Every support request, newest first, with who sent it.
 *
 * @param string $status  one of SUPPORT_STATUSES, or '' for all
 * @param string $type    one of SUPPORT_TYPES' keys, or '' for all
 * @return list<array<string,mixed>>
 */
function admin_requests(string $status = '', string $type = '', int $limit = 200): array
{
    $where  = [];
    $params = [];

    // Validated against the known lists rather than interpolated, so a crafted
    // query string cannot reach the SQL.
    if ($status !== '' && in_array($status, SUPPORT_STATUSES, true)) {
        $where[]  = 'sr.status = ?';
        $params[] = $status;
    }
    if ($type !== '' && array_key_exists($type, SUPPORT_TYPES)) {
        $where[]  = 'sr.request_type = ?';
        $params[] = $type;
    }

    $sql = 'SELECT sr.request_id, sr.user_id, sr.request_type, sr.subject,
                   sr.message, sr.status, sr.created_at,
                   u.full_name, u.email
              FROM support_request sr
              JOIN user u ON u.user_id = sr.user_id';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY sr.request_id DESC LIMIT ' . (int) max(1, min($limit, 500));

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * One request, with its sender. Null when there is no such row.
 *
 * @return array<string,mixed>|null
 */
function admin_request(int $requestId): ?array
{
    $stmt = db()->prepare(
        'SELECT sr.request_id, sr.user_id, sr.request_type, sr.subject,
                sr.message, sr.status, sr.created_at,
                u.full_name, u.email
           FROM support_request sr
           JOIN user u ON u.user_id = sr.user_id
          WHERE sr.request_id = ? LIMIT 1'
    );
    $stmt->execute([$requestId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * How many requests sit in each status, and how many in total.
 *
 * Every status is present in the result even when it is empty, so the screen
 * can show "0 resolved" rather than silently omitting the column.
 *
 * @return array<string,int>
 */
function admin_status_counts(): array
{
    $counts = array_fill_keys(SUPPORT_STATUSES, 0);
    $counts['total'] = 0;

    foreach (db()->query('SELECT status, COUNT(*) AS n FROM support_request GROUP BY status')
                 ->fetchAll() as $row) {
        $status = (string) $row['status'];
        if (array_key_exists($status, $counts)) {
            $counts[$status] = (int) $row['n'];
        }
        $counts['total'] += (int) $row['n'];
    }

    return $counts;
}

/**
 * Feedback only: the AI-quality reports and the suggestions.
 *
 * @param string $status one of SUPPORT_STATUSES, or '' for all
 * @return list<array<string,mixed>>
 */
function admin_feedback(string $status = '', int $limit = 200): array
{
    $out = [];
    foreach (ADMIN_FEEDBACK_TYPES as $type) {
        foreach (admin_requests($status, $type, $limit) as $row) {
            $out[] = $row;
        }
    }

    // Each type came back newest-first on its own; the merge has to be sorted
    // again or the screen shows one type and then the other.
    usort($out, static fn(array $a, array $b): int =>
        (int) $b['request_id'] <=> (int) $a['request_id']);

    return array_slice($out, 0, $limit);
}

/**
 * How many requests of each type, for the dashboard.
 *
 * Every known type is present even when it is empty, so a type nobody has used
 * reads as zero rather than vanishing.
 *
 * @return array<string,int>
 */
function admin_type_counts(): array
{
    $counts = array_fill_keys(array_keys(SUPPORT_TYPES), 0);

    foreach (db()->query('SELECT request_type, COUNT(*) AS n FROM support_request
                          GROUP BY request_type')->fetchAll() as $row) {
        $type = (string) $row['request_type'];
        if (array_key_exists($type, $counts)) {
            $counts[$type] = (int) $row['n'];
        }
    }

    return $counts;
}

/**
 * How many requests are waiting on somebody, for the dashboard headline.
 *
 * Open and in progress both count. Resolved and closed do not: the point of the
 * number is "how much is on us", not "how much has ever arrived".
 */
function admin_open_count(): int
{
    $counts = admin_status_counts();

    return (int) ($counts['open'] ?? 0) + (int) ($counts['in_progress'] ?? 0);
}

/* -------------------------------------------------------------------------
   Moving one along
   ------------------------------------------------------------------------- */

/**
 * Change a request's status.
 *
 * The first thing in EduFlex that writes to support_request after creation, and
 * therefore the first thing that makes sub-module 7.2 "View Request Status"
 * mean anything to the learner who sent it.
 *
 * @return array{ok:bool, error:?string, changed:bool}
 */
function admin_set_status(int $requestId, string $status): array
{
    $fail = static fn(string $m): array => ['ok' => false, 'error' => $m, 'changed' => false];

    if (!in_array($status, SUPPORT_STATUSES, true)) {
        return $fail('That is not a status a request can be in.');
    }

    $current = admin_request($requestId);
    if ($current === null) {
        return $fail('That request no longer exists.');
    }

    // Saying so beats writing the same value and reporting a change that did
    // not happen.
    if ((string) $current['status'] === $status) {
        return ['ok' => true, 'error' => null, 'changed' => false];
    }

    try {
        $stmt = db()->prepare('UPDATE support_request SET status = ? WHERE request_id = ?');
        $stmt->execute([$status, $requestId]);
    } catch (PDOException $e) {
        error_log('EduFlex admin_set_status failed: ' . $e->getMessage());
        return $fail('The status could not be saved. Try again.');
    }

    /* Tell the learner something moved. notify() swallows and logs its own
       failures, so a notification problem can never break the status change
       that caused it. Rule 8. */
    notify(
        (int) $current['user_id'],
        'support',
        'Your support request was updated',
        sprintf('"%s" is now %s.',
            (string) $current['subject'], support_status_label($status))
    );

    return ['ok' => true, 'error' => null, 'changed' => true];
}
