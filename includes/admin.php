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

/**
 * The conversation summary every queue row carries, as SQL select columns.
 *
 * reply_count is how many messages follow the original. last_from_team is the
 * from_team of the newest reply, or NULL when there is none. Together they
 * answer "is the next move ours?" without a query per row; see
 * admin_needs_reply(). One constant so admin_requests() and admin_request()
 * cannot disagree about it.
 */
const ADMIN_THREAD_COLUMNS =
    '(SELECT COUNT(*) FROM support_reply r WHERE r.request_id = sr.request_id) AS reply_count,
     (SELECT r.from_team FROM support_reply r WHERE r.request_id = sr.request_id
       ORDER BY r.reply_id DESC LIMIT 1) AS last_from_team';

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
                   u.full_name, u.email, ' . ADMIN_THREAD_COLUMNS . '
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
                u.full_name, u.email, ' . ADMIN_THREAD_COLUMNS . '
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

/**
 * Is the next message in this conversation the team's to write?
 *
 * Yes when the request is still open or being looked at, and either nobody has
 * replied yet or the newest reply is the learner's. A resolved or closed
 * request never needs a reply: if the learner writes back on a resolved one,
 * support_learner_reply() reopens it, which brings it back here.
 *
 * This stands in for a team notification. See SCHEMA-NOTES.md section 11.
 */
function admin_needs_reply(array $row): bool
{
    if (!in_array((string) ($row['status'] ?? ''), ['open', 'in_progress'], true)) {
        return false;
    }
    if ((int) ($row['reply_count'] ?? 0) === 0) {
        return true;
    }
    return (int) ($row['last_from_team'] ?? 0) !== 1;
}

/** How many requests are waiting for the team to write, for the dashboard. */
function admin_needs_reply_count(): int
{
    $n = 0;
    foreach (admin_requests('', '', 500) as $row) {
        if (admin_needs_reply($row)) {
            $n++;
        }
    }
    return $n;
}

/* -------------------------------------------------------------------------
   The people
   ------------------------------------------------------------------------- */

/**
 * Every learner, with how much of the study each of them has actually done.
 *
 * Team accounts are excluded. The list exists so a participant's withdrawal
 * request can be actioned, and the team cannot withdraw itself.
 *
 * The counts are what makes it useful rather than a names list: before deleting
 * somebody you can see what goes with them, and during the evaluation you can
 * see at a glance who has actually used the system.
 *
 * @return list<array<string,mixed>>
 */
function admin_users(): array
{
    $sql = 'SELECT u.user_id, u.full_name, u.email, u.created_at, u.email_verified_at,
                   (SELECT COUNT(*) FROM learning_resource r WHERE r.user_id = u.user_id)
                     AS materials,
                   (SELECT COUNT(*) FROM topic_progress t WHERE t.user_id = u.user_id)
                     AS topics,
                   (SELECT COUNT(*) FROM activity_attempt a WHERE a.user_id = u.user_id)
                     AS attempts,
                   (SELECT COUNT(*) FROM support_request s WHERE s.user_id = u.user_id)
                     AS requests
              FROM user u
             WHERE u.is_admin = 0
          ORDER BY u.user_id';

    return db()->query($sql)->fetchAll();
}

/**
 * One learner, by id, for the screens that act on a single person.
 *
 * Returns null for a team account as well as for a missing one, so a crafted
 * id cannot be used to export or delete another admin through a screen meant
 * for participants.
 *
 * @return array<string,mixed>|null
 */
function admin_user(int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT user_id, full_name, email, created_at, is_admin
           FROM user WHERE user_id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    if ($row === false || (int) $row['is_admin'] === 1) {
        return null;
    }

    return $row;
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

/* -------------------------------------------------------------------------
   Replying
   ------------------------------------------------------------------------- */

/**
 * The team replies to a learner's request.
 *
 * The caller has already passed admin_require(); this does not re-check the
 * flag, the same way admin_set_status() does not.
 *
 * Replying to an open request moves it to in_progress, because a reply means
 * somebody is looking. $resolve also marks it resolved in the same step, for
 * the common case of "here is the answer, done". Either way the learner gets
 * ONE notification, written here, by the function that performs the event, and
 * never by a page render. Rule 8.
 *
 * The team can reply on any status, including closed: saying something after a
 * request is closed is the team's call, not a validation error.
 *
 * @return array{ok:bool, error:?string, status:string}
 */
function admin_reply(int $adminId, int $requestId, string $message, bool $resolve = false): array
{
    $request = admin_request($requestId);
    if ($request === null) {
        return ['ok' => false, 'error' => 'That request no longer exists.', 'status' => ''];
    }
    $before = (string) $request['status'];
    $fail   = static fn(string $m): array => ['ok' => false, 'error' => $m, 'status' => $before];

    $problem = support_reply_error($message);
    if ($problem !== null) {
        return $fail($problem);
    }

    if (support_store_reply($requestId, $adminId, true, $message) === null) {
        return $fail('The reply could not be saved. Try again.');
    }

    $after = $before;
    if ($resolve) {
        $after = 'resolved';
    } elseif ($before === 'open') {
        $after = 'in_progress';
    }

    if ($after !== $before) {
        try {
            $stmt = db()->prepare('UPDATE support_request SET status = ? WHERE request_id = ?');
            $stmt->execute([$after, $requestId]);
        } catch (PDOException $e) {
            // The reply is stored and the learner can read it, which is the
            // point. A status that did not move is worth a log line, not a
            // failure message about a reply that was in fact sent.
            error_log('EduFlex admin_reply() status change failed: ' . $e->getMessage());
            $after = $before;
        }
    }

    $preview = trim(preg_replace('/\s+/u', ' ', trim($message)) ?? '');
    if (mb_strlen($preview) > 140) {
        $preview = rtrim(mb_substr($preview, 0, 137)) . '...';
    }

    notify(
        (int) $request['user_id'],
        'support',
        'The project team replied to your request',
        sprintf('"%s"%s: %s Open Support to read the whole reply.',
            (string) $request['subject'],
            $after === 'resolved' && $before !== 'resolved' ? ' (now Resolved)' : '',
            $preview)
    );

    return ['ok' => true, 'error' => null, 'status' => $after];
}

/* -------------------------------------------------------------------------
   Manage User Accounts (app/admin_users.php, app/admin_user.php)

   Added 19 November 2026. Every registered account, searchable, with edit,
   activate/deactivate, export and delete for learners.

   TEAM ACCOUNTS ARE LISTED BUT NEVER ACTED ON. Every write below goes through
   admin_user(), which returns null for an is_admin account, so a crafted id
   cannot deactivate, edit or delete the help desk from a screen meant for
   participants. Team access is still granted and revoked only with
   tools/make_admin.php, and no statement here so much as mentions the flag in
   an UPDATE: tests/admin_test.php greps for exactly that.
   ------------------------------------------------------------------------- */

/** What "account_status" means on screen. Anything but 'active' cannot sign in. */
function admin_account_status_label(string $status): string
{
    return $status === 'active' ? 'Active' : 'Deactivated';
}

/** Reuse the mastery band colours, as the support statuses do. */
function admin_account_status_band(string $status): string
{
    return $status === 'active' ? 'mastered' : 'weak';
}

/** The sort orders the list offers, and their SQL. Never taken from the URL. */
const ADMIN_ACCOUNT_SORTS = [
    'newest' => ['Newest first', 'u.user_id DESC'],
    'oldest' => ['Oldest first', 'u.user_id ASC'],
    'name'   => ['Name, A to Z', 'u.full_name ASC, u.user_id ASC'],
];

/** The activity counts both account screens show, as SQL select columns. */
const ADMIN_ACCOUNT_COUNT_COLUMNS =
    '(SELECT COUNT(*) FROM learning_resource r WHERE r.user_id = u.user_id) AS materials,
     (SELECT COUNT(*) FROM topic_progress t WHERE t.user_id = u.user_id) AS topics,
     (SELECT COUNT(*) FROM activity_attempt a WHERE a.user_id = u.user_id) AS attempts,
     (SELECT COUNT(*) FROM support_request s WHERE s.user_id = u.user_id) AS requests';

/**
 * Every account that matches the filters.
 *
 * Every filter is checked against a fixed list before it reaches the SQL, and
 * the search text is bound, never interpolated. LIKE wildcards in the search
 * are escaped, so searching for "50%" finds a literal 50% rather than
 * everything starting with 50.
 *
 * @param string $search   matched against name and email; a number also matches the id
 * @param string $status   'active', 'inactive' or '' for both
 * @param string $verified 'yes', 'no' or '' for both
 * @param string $role     'learner', 'team' or '' for both
 * @param string $sort     a key of ADMIN_ACCOUNT_SORTS
 * @return list<array<string,mixed>>
 */
function admin_accounts(
    string $search = '',
    string $status = '',
    string $verified = '',
    string $role = '',
    string $sort = 'newest',
    int $limit = 500
): array {
    $where  = [];
    $params = [];

    $search = trim($search);
    if ($search !== '') {
        $like = '%' . strtr(mb_strtolower($search), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $match = "(LOWER(u.full_name) LIKE ? ESCAPE '!' OR LOWER(u.email) LIKE ? ESCAPE '!'";
        $params[] = $like;
        $params[] = $like;
        if (ctype_digit($search)) {
            $match   .= ' OR u.user_id = ?';
            $params[] = (int) $search;
        }
        $where[] = $match . ')';
    }

    if ($status === 'active') {
        $where[] = "u.account_status = 'active'";
    } elseif ($status === 'inactive') {
        $where[] = "u.account_status <> 'active'";
    }

    if ($verified === 'yes') {
        $where[] = 'u.email_verified_at IS NOT NULL';
    } elseif ($verified === 'no') {
        $where[] = 'u.email_verified_at IS NULL';
    }

    if ($role === 'learner') {
        $where[] = 'u.is_admin = 0';
    } elseif ($role === 'team') {
        $where[] = 'u.is_admin = 1';
    }

    $order = (ADMIN_ACCOUNT_SORTS[$sort] ?? ADMIN_ACCOUNT_SORTS['newest'])[1];

    $sql = 'SELECT u.user_id, u.full_name, u.email, u.program, u.year_level,
                   u.account_status, u.is_admin, u.email_verified_at, u.created_at, '
         . ADMIN_ACCOUNT_COUNT_COLUMNS . '
              FROM user u';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY ' . $order . ' LIMIT ' . (int) max(1, min($limit, 1000));

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Headline numbers for the accounts screen. Every key is always present.
 *
 * @return array{total:int, active:int, inactive:int, unverified:int, team:int}
 */
function admin_account_summary(): array
{
    $row = db()->query(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN account_status = 'active' THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN account_status <> 'active' THEN 1 ELSE 0 END) AS inactive,
                SUM(CASE WHEN email_verified_at IS NULL THEN 1 ELSE 0 END) AS unverified,
                SUM(CASE WHEN is_admin = 1 THEN 1 ELSE 0 END) AS team
           FROM user"
    )->fetch() ?: [];

    $out = [];
    foreach (['total', 'active', 'inactive', 'unverified', 'team'] as $k) {
        $out[$k] = (int) ($row[$k] ?? 0);
    }
    return $out;
}

/**
 * One account, team or learner, with its activity counts, for the details
 * screen. Read-only; whether it may be ACTED on is admin_user()'s question.
 *
 * @return array<string,mixed>|null
 */
function admin_account(int $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT u.user_id, u.full_name, u.email, u.program, u.year_level,
                u.account_status, u.is_admin, u.email_verified_at, u.avatar_path,
                u.created_at, ' . ADMIN_ACCOUNT_COUNT_COLUMNS . '
           FROM user u WHERE u.user_id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * One account's support requests, newest first, for its details screen.
 *
 * @return list<array<string,mixed>>
 */
function admin_account_requests(int $userId, int $limit = 20): array
{
    $stmt = db()->prepare(
        'SELECT sr.request_id, sr.request_type, sr.subject, sr.status, sr.created_at
           FROM support_request sr
          WHERE sr.user_id = ?
       ORDER BY sr.request_id DESC
          LIMIT ' . (int) max(1, min($limit, 100))
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Edit a learner's name, email, programme and year level.
 *
 * The same rules as the learner's own Settings form (app/actions/save_profile.php),
 * so the team cannot store something the learner could not: a name up to 150
 * characters, a valid and unique email, a programme up to 100, year 1 to 5.
 *
 * Changing the email clears email_verified_at and revokes the learner's trusted
 * browsers, exactly as a learner changing it themselves does, so the new
 * address has to be proved before it is trusted. The learner is told through
 * notify(), because their account changed and they did not change it.
 *
 * @return array{ok:bool, errors:array<string,string>, email_changed:bool, changed:bool}
 */
function admin_update_account(
    int $userId,
    string $fullName,
    string $email,
    string $program,
    int $yearLevel
): array {
    $fail = static fn(array $errors): array =>
        ['ok' => false, 'errors' => $errors, 'email_changed' => false, 'changed' => false];

    $target = admin_user($userId);
    if ($target === null) {
        return $fail(['form' => 'That account cannot be edited here. It no longer exists, '
                              . 'or it is a team account.']);
    }

    $fullName = trim($fullName);
    $email    = strtolower(trim($email));
    $program  = trim($program);

    $errors = [];
    if ($fullName === '') {
        $errors['full_name'] = 'Enter the learner\'s full name.';
    } elseif (mb_strlen($fullName) > 150) {
        $errors['full_name'] = 'Keep the name under 150 characters.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
        $errors['email'] = 'That does not look like a valid email address.';
    }
    if ($program === '') {
        $errors['program'] = 'Enter a programme.';
    } elseif (mb_strlen($program) > 100) {
        $errors['program'] = 'Keep the programme under 100 characters.';
    }
    if ($yearLevel < 1 || $yearLevel > 5) {
        $errors['year_level'] = 'Choose a year level from 1 to 5.';
    }
    if ($errors !== []) {
        return $fail($errors);
    }

    $stmt = db()->prepare('SELECT user_id FROM user WHERE email = ? AND user_id <> ? LIMIT 1');
    $stmt->execute([$email, $userId]);
    if ($stmt->fetch()) {
        return $fail(['email' => 'Another account already uses that email address.']);
    }

    $current = admin_account($userId);
    $emailChanged = $email !== strtolower((string) ($current['email'] ?? ''));
    $changed = $emailChanged
        || $fullName !== (string) ($current['full_name'] ?? '')
        || $program !== (string) ($current['program'] ?? '')
        || $yearLevel !== (int) ($current['year_level'] ?? 0);

    if (!$changed) {
        return ['ok' => true, 'errors' => [], 'email_changed' => false, 'changed' => false];
    }

    /* Only a transaction this function opened is this function's to roll back.
       It once caught the failure of its own beginTransaction() inside a
       caller's transaction and rolled back the CALLER's work, after which
       everything the caller did next was committed for real. */
    $pdo = db();
    $own = !$pdo->inTransaction();
    try {
        if ($own) {
            $pdo->beginTransaction();
        }
        $pdo->prepare(
            'UPDATE user SET full_name = ?, email = ?, program = ?, year_level = ?
              WHERE user_id = ?'
        )->execute([$fullName, $email, $program, $yearLevel, $userId]);

        if ($emailChanged) {
            $pdo->prepare('UPDATE user SET email_verified_at = NULL WHERE user_id = ?')
                ->execute([$userId]);
            if (function_exists('verification_revoke')) {
                verification_revoke($userId);
            }
        }
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('EduFlex admin_update_account() failed: ' . $e->getMessage());
        return $fail(['form' => 'The changes could not be saved. Try again.']);
    }

    notify(
        $userId,
        'system',
        'The project team updated your account details',
        $emailChanged
            ? 'Your sign-in email is now ' . $email . '. You will be asked to verify it the next '
              . 'time you sign in.'
            : 'Check your details in Settings, and use Support if anything looks wrong.'
    );

    return ['ok' => true, 'errors' => [], 'email_changed' => $emailChanged, 'changed' => true];
}

/**
 * Activate or deactivate a learner's account.
 *
 * A deactivated account cannot sign in (auth_login()) and is signed out of any
 * open session on its next request (auth_user()). Nothing is deleted: their
 * materials, mastery and conversations are all still there when the account is
 * activated again, which is the whole difference from Delete.
 *
 * Only reactivation is announced. A deactivated learner cannot open their
 * notifications, so a "you were deactivated" row would sit unread and then
 * greet them, stale, on the day they were let back in.
 *
 * @return array{ok:bool, error:?string, changed:bool}
 */
function admin_set_account_status(int $userId, string $status): array
{
    if (!in_array($status, ['active', 'inactive'], true)) {
        return ['ok' => false, 'error' => 'That is not a status an account can have.', 'changed' => false];
    }

    $target = admin_user($userId);
    if ($target === null) {
        return ['ok' => false, 'changed' => false,
                'error' => 'That account cannot be changed here. It no longer exists, '
                         . 'or it is a team account.'];
    }

    $current = (string) (admin_account($userId)['account_status'] ?? '');
    $isActive = $current === 'active';
    if (($status === 'active') === $isActive) {
        return ['ok' => true, 'error' => null, 'changed' => false];
    }

    try {
        db()->prepare('UPDATE user SET account_status = ? WHERE user_id = ?')
            ->execute([$status, $userId]);
    } catch (Throwable $e) {
        error_log('EduFlex admin_set_account_status() failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The change could not be saved. Try again.', 'changed' => false];
    }

    if ($status === 'active') {
        notify(
            $userId,
            'system',
            'Your account has been reactivated',
            'The project team reactivated your account. Everything you had before is still here.'
        );
    }

    return ['ok' => true, 'error' => null, 'changed' => true];
}
