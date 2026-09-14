<?php
/**
 * EduFlex — authentication and session handling.
 *
 * Covers modules 1 to 6 of Account and Access Management, minus Manage
 * Subscription. Include this at the very top of any page, before output.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/avatar.php';

/* -------------------------------------------------------------------------
   Session lifetime

   A session that never expires is a session that stays valid on a shared
   library machine after the learner walks away. Two limits, both checked on
   every request by auth_is_logged_in():

   idle     time since the last request on this session
   absolute time since the login, regardless of activity

   The absolute limit is the one that matters: an attacker who steals a session
   cookie keeps it alive indefinitely just by using it, so an idle limit alone
   does not bound the damage.
   ------------------------------------------------------------------------- */

const SESSION_IDLE_SECONDS     = 7200;   // 2 hours without a request
const SESSION_ABSOLUTE_SECONDS = 43200;  // 12 hours since signing in

/**
 * The one password rule in the system.
 *
 * Registration and the change-password form both call auth_password_error().
 * A second rule written next to the second form is how a system ends up
 * accepting a password at one screen that it rejects at another.
 */
const AUTH_PASSWORD_MIN = 8;

/**
 * @return string|null null when the password is acceptable
 */
function auth_password_error(string $password): ?string
{
    if (mb_strlen($password) < AUTH_PASSWORD_MIN) {
        return 'Password must be at least ' . AUTH_PASSWORD_MIN . ' characters.';
    }
    return null;
}

/**
 * Start the session with hardened cookie settings.
 * Safe to call on every page; it returns immediately if already started.
 */
function auth_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Errors go to the log, never to the page. Set before anything can fail,
    // and a no-op on the CLI so the test suites still show their output.
    security_harden_error_output();

    // Cookie parameters can only be set before anything is output. If output
    // has already started (a stray blank line at the end of an include, or a
    // byte-order mark), skip the hardening rather than emitting a warning
    // into the middle of the page.
    if (!headers_sent()) {
        // One call site for every security header in the system. Every page and
        // every endpoint reaches auth_boot(), so none of them can forget.
        security_headers();

        session_set_cookie_params([
            'lifetime' => 0,      // expires when the browser closes
            'path'     => '/',
            'httponly' => true,   // JavaScript cannot read the session cookie
            'samesite' => 'Lax',  // blocks the simple CSRF cases
            // Detected rather than hard-coded, because sending Secure over
            // plain HTTP means the browser discards the cookie and nobody can
            // sign in at all. Serve over HTTPS and this turns itself on.
            'secure'   => security_is_https(),
        ]);
        session_start();
        return;
    }

    // Headers already sent. PHP cannot issue the session cookie, so a browser
    // session is impossible here. Log it: this always means a bug upstream.
    error_log('EduFlex: auth_boot() called after output started. '
            . 'Check for stray whitespace before the opening PHP tag, or after '
            . 'the closing tag, in an included file.');
}

/* -------------------------------------------------------------------------
   Registration
   ------------------------------------------------------------------------- */

/**
 * Create a learner account.
 *
 * @return array{ok:bool, errors:array<string,string>, user_id:?int}
 */
function auth_register(string $fullName, string $email, string $password, bool $agreed): array
{
    $errors = [];

    $fullName = trim($fullName);
    $email    = strtolower(trim($email));

    if ($fullName === '') {
        $errors['full_name'] = 'Enter your full name.';
    } elseif (mb_strlen($fullName) > 150) {
        $errors['full_name'] = 'Name is too long.';
    }

    if ($email === '') {
        $errors['email'] = 'Enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'That does not look like a valid email address.';
    } elseif (mb_strlen($email) > 255) {
        $errors['email'] = 'Email address is too long.';
    }

    $passwordError = auth_password_error($password);
    if ($passwordError !== null) {
        $errors['password'] = $passwordError;
    }

    if (!$agreed) {
        $errors['agree'] = 'You must agree to the Terms and Privacy Policy.';
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors, 'user_id' => null];
    }

    $pdo = db();

    // Check for an existing account before attempting the insert, so the
    // learner gets a clear message rather than a constraint violation.
    $stmt = $pdo->prepare('SELECT user_id FROM user WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return [
            'ok'      => false,
            'errors'  => ['email' => 'An account with that email already exists.'],
            'user_id' => null,
        ];
    }

    // PASSWORD_DEFAULT is bcrypt today and upgrades automatically in future
    // PHP versions. Never store, log or email the plain password.
    $hash = password_hash($password, PASSWORD_DEFAULT);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'INSERT INTO user (full_name, email, password_hash, program, year_level, account_status)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $fullName,
            $email,
            $hash,
            'BS Information Technology',
            1,
            'active',
        ]);

        $userId = (int) $pdo->lastInsertId();

        // EduFlex is a non-commercial prototype, so every account gets the
        // free plan. The row exists only to satisfy the documented ERD.
        $stmt = $pdo->prepare(
            'INSERT INTO subscription (user_id, plan_type, status) VALUES (?, ?, ?)'
        );
        $stmt->execute([$userId, 'free', 'active']);

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('EduFlex register failed: ' . $e->getMessage());
        return [
            'ok'      => false,
            'errors'  => ['form' => 'Could not create the account. Try again.'],
            'user_id' => null,
        ];
    }

    /* Welcome notification, so the bell is not empty on the first visit.
       Deliberately outside the transaction: this used to be an INSERT inside
       it, which meant a failed notification rolled back a perfectly good
       registration. notify() logs and returns false instead. */
    notify(
        $userId,
        'system',
        'Welcome to EduFlex',
        'Upload a lecture note or reviewer and EduFlex will read it, work out what '
        . 'it covers, and build your first practice set from it.'
    );

    return ['ok' => true, 'errors' => [], 'user_id' => $userId];
}

/* -------------------------------------------------------------------------
   Login and logout
   ------------------------------------------------------------------------- */

/**
 * Verify credentials and start an authenticated session.
 *
 * @return array{ok:bool, errors:array<string,string>}
 */
function auth_login(string $email, string $password): array
{
    $email = strtolower(trim($email));

    if ($email === '' || $password === '') {
        return ['ok' => false, 'errors' => ['form' => 'Enter your email and password.']];
    }

    /* Throttle before touching the database for this email. Checked first so a
       blocked attempt costs nothing and reveals nothing, not even the timing of
       a password comparison. */
    $ip       = security_client_ip();
    $throttle = login_throttle_check($email, $ip);
    if (!$throttle['allowed']) {
        return [
            'ok'     => false,
            'errors' => ['form' => login_throttle_message($throttle['retry_after'])],
        ];
    }

    $stmt = db()->prepare(
        'SELECT user_id, full_name, email, password_hash, account_status
           FROM user WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // One message for both "no such account" and "wrong password". Telling
    // the difference lets an attacker enumerate which emails are registered.
    $generic = ['ok' => false, 'errors' => ['form' => 'Email or password is incorrect.']];

    if (!$user) {
        // Spend roughly the same time as a real verification would, so the
        // response time does not reveal whether the account exists.
        password_verify($password, '$2y$10$usesomesillystringforsalt00000000000000000000000000000');
        // Recorded even though no such account exists. Skipping it here would
        // make the throttle itself an oracle: unlimited guesses at an unknown
        // email, five at a real one.
        login_throttle_record_failure($email, $ip);
        return $generic;
    }

    if (!password_verify($password, $user['password_hash'])) {
        login_throttle_record_failure($email, $ip);
        return $generic;
    }

    if ($user['account_status'] !== 'active') {
        // Counted too: this branch confirms the account exists, so it must not
        // be an unlimited probe.
        login_throttle_record_failure($email, $ip);
        return ['ok' => false, 'errors' => ['form' => 'This account is not active. Contact support.']];
    }

    // Rehash if PHP's default cost or algorithm has moved on.
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $upd = db()->prepare('UPDATE user SET password_hash = ? WHERE user_id = ?');
        $upd->execute([$newHash, $user['user_id']]);
    }

    auth_boot();
    // New session ID on privilege change, to defeat session fixation.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $_SESSION['user_id']   = (int) $user['user_id'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email']     = $user['email'];
    $_SESSION['logged_in_at'] = time();
    $_SESSION['last_seen_at'] = time();

    /* A learner who mistyped four times and then got it right is not punished.
       Pruning here rather than on a schedule keeps the table small without a
       cron job, which this project does not have. */
    login_throttle_clear($email, $ip);
    login_throttle_prune();

    return ['ok' => true, 'errors' => []];
}

function auth_logout(): void
{
    auth_boot();
    $_SESSION = [];

    if (ini_get('session.use_cookies') && !headers_sent()) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/* -------------------------------------------------------------------------
   Session state
   ------------------------------------------------------------------------- */

function auth_is_logged_in(): bool
{
    auth_boot();

    if (!isset($_SESSION['user_id'])) {
        return false;
    }

    // Expired sessions are destroyed here rather than merely reported, so a
    // stale cookie cannot be reused on the next request either.
    if (auth_session_expired()) {
        auth_logout();
        flash_set('error', 'You were signed out because the session expired. Please sign in again.');
        return false;
    }

    $_SESSION['last_seen_at'] = time();
    return true;
}

/**
 * Has this session outlived either limit?
 *
 * A session with no recorded timestamps predates this check, so it is treated
 * as expired rather than trusted forever.
 */
function auth_session_expired(): bool
{
    $now      = time();
    $loginAt  = (int) ($_SESSION['logged_in_at'] ?? 0);
    $lastSeen = (int) ($_SESSION['last_seen_at'] ?? $loginAt);

    if ($loginAt <= 0) {
        return true;
    }
    if (($now - $loginAt) > SESSION_ABSOLUTE_SECONDS) {
        return true;
    }
    return ($now - $lastSeen) > SESSION_IDLE_SECONDS;
}

/* -------------------------------------------------------------------------
   Where it is safe to send somebody after login
   ------------------------------------------------------------------------- */

/**
 * Validate a "next" destination from the login form.
 *
 * The old check rejected anything matching `^(https?:)?//`, which let
 * `/\evil.example.com` through. Browsers treat a slash followed by a backslash
 * the same as two slashes, so that value was a working open redirect: sign in
 * legitimately, land on somebody else's site. Verified against the running
 * system before this was written.
 *
 * Rejecting patterns one at a time loses this game. This accepts only what it
 * recognises: a same-site path, starting with a single "/" or with a bare
 * filename, containing no scheme, no backslash and no control characters.
 *
 * @return string the destination to use, or $fallback
 */
function auth_safe_redirect_target(?string $candidate, string $fallback = 'app/dashboard.php'): string
{
    if (!is_string($candidate)) {
        return $fallback;
    }

    // No trimming first: leading whitespace and control bytes are how these
    // checks get bypassed, so their presence is itself grounds for refusal.
    if ($candidate === '' || $candidate !== trim($candidate)) {
        return $fallback;
    }

    // A control character, including the tab, newline and null that browsers
    // and header parsers strip or stop at.
    if (preg_match('/[\x00-\x1F\x7F]/', $candidate)) {
        return $fallback;
    }

    // A backslash is never legitimate in a URL path here, and is the exact
    // character the old check missed.
    if (str_contains($candidate, '\\')) {
        return $fallback;
    }

    // Any scheme, and protocol-relative "//host".
    if (str_starts_with($candidate, '//') || preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $candidate)) {
        return $fallback;
    }

    // An encoded slash or backslash, which becomes one after decoding.
    if (preg_match('/%(?:2f|5c)/i', $candidate)) {
        return $fallback;
    }

    // Path traversal. Every real destination in EduFlex is a flat path.
    if (str_contains($candidate, '..')) {
        return $fallback;
    }

    // What is left must look like a path: "/something" or "something.php".
    if (!preg_match('#^/?[A-Za-z0-9_./-]*[A-Za-z0-9_.-](\?[A-Za-z0-9_=&.%+-]*)?$#', $candidate)) {
        return $fallback;
    }

    return $candidate;
}

/**
 * The signed-in user's row, or null.
 *
 * @return array<string,mixed>|null
 */
function auth_user(): ?array
{
    if (!auth_is_logged_in()) {
        return null;
    }

    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $stmt = db()->prepare(
        'SELECT user_id, full_name, email, program, year_level, account_status, created_at
           FROM user WHERE user_id = ? LIMIT 1'
    );
    $stmt->execute([$_SESSION['user_id']]);
    $row = $stmt->fetch();

    // The session points at a user who no longer exists. Clear it.
    if (!$row) {
        auth_logout();
        return null;
    }

    return $cached = $row;
}

/**
 * Gate a page. Put this at the top of every screen inside /app.
 */
function auth_require_login(string $loginPath = '../login.php'): void
{
    if (auth_is_logged_in() && auth_user() !== null) {
        return;
    }
    redirect($loginPath . '?next=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
}

/**
 * First name only, for greetings.
 */
function auth_first_name(): string
{
    $user = auth_user();
    if (!$user) {
        return 'there';
    }
    $parts = preg_split('/\s+/', trim((string) $user['full_name']));
    return $parts[0] ?: 'there';
}

/* -------------------------------------------------------------------------
   Change password
   ------------------------------------------------------------------------- */

/**
 * Replace a learner's password.
 *
 * The current password is verified first. Without that check, anyone who got
 * hold of a live session could lock the real owner out of their account, and a
 * session is easier to come by than a password.
 *
 * @return array{ok:bool, errors:array<string,string>}
 */
function auth_change_password(int $userId, string $current, string $new, string $confirm): array
{
    $errors = [];

    if ($current === '') {
        $errors['current_password'] = 'Enter your current password.';
    }

    // The same rule registration uses. See auth_password_error().
    $passwordError = auth_password_error($new);
    if ($passwordError !== null) {
        $errors['new_password'] = $passwordError;
    } elseif ($new !== $confirm) {
        $errors['confirm_password'] = 'The two new passwords do not match.';
    } elseif ($new === $current) {
        $errors['new_password'] = 'The new password is the same as the current one.';
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    $stmt = db()->prepare('SELECT password_hash FROM user WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $hash = $stmt->fetchColumn();

    if ($hash === false) {
        return ['ok' => false, 'errors' => ['form' => 'That account no longer exists.']];
    }

    if (!password_verify($current, (string) $hash)) {
        return [
            'ok'     => false,
            'errors' => ['current_password' => 'That is not your current password.'],
        ];
    }

    try {
        $stmt = db()->prepare('UPDATE user SET password_hash = ? WHERE user_id = ?');
        $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
    } catch (PDOException $e) {
        error_log('EduFlex password change failed: ' . $e->getMessage());
        return ['ok' => false, 'errors' => ['form' => 'The password could not be changed. Try again.']];
    }

    // A new session ID on a credential change, for the same reason auth_login()
    // does it: any session ID an attacker already holds stops working.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    notify(
        $userId,
        'system',
        'Your password was changed',
        'The change took effect immediately. If this was not you, change it again '
        . 'and tell the project team through the Support screen.'
    );

    return ['ok' => true, 'errors' => []];
}

/* -------------------------------------------------------------------------
   Withdrawing: export and delete

   Chapter III promises participants can withdraw and take their data with
   them. These two functions are that promise in code. They are an ethics
   commitment, not a feature, so neither is gated behind anything.
   ------------------------------------------------------------------------- */

/**
 * Everything EduFlex holds about one learner, ready to be encoded as JSON.
 *
 * Every query filters by user_id, most by joining learning_resource, the same
 * way every other learner-scoped read in the system does.
 *
 * The password hash is deliberately absent: it is not the learner's data in any
 * useful sense, and publishing it would only help somebody attack it offline.
 *
 * @return array<string,mixed>
 */
function auth_export_data(int $userId): array
{
    $rows = static function (string $sql, array $params) : array {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    };

    $account = $rows(
        'SELECT user_id, full_name, email, program, year_level, account_status,
                avatar_path, created_at
           FROM user WHERE user_id = ?',
        [$userId]
    );

    $materials = $rows(
        'SELECT resource_id, title, original_name, file_type, file_size,
                processing_status, char_count, chunk_count, uploaded_at, processed_at
           FROM learning_resource WHERE user_id = ? ORDER BY uploaded_at ASC',
        [$userId]
    );

    $topics = $rows(
        'SELECT tp.topic_progress_id, tp.topic_name, tp.mastery_score,
                tp.scored_items, tp.weakness_priority, lr.title AS material
           FROM topic_progress tp
           JOIN learning_resource lr ON lr.resource_id = tp.resource_id
          WHERE tp.user_id = ?
       ORDER BY tp.topic_progress_id ASC',
        [$userId]
    );

    $attempts = $rows(
        'SELECT aa.attempt_id, la.title AS activity, la.bloom_level,
                tp.topic_name, aa.score, aa.total_items, aa.started_at, aa.completed_at
           FROM activity_attempt aa
           JOIN learning_activity la ON la.activity_id = aa.activity_id
           LEFT JOIN topic_progress tp ON tp.topic_progress_id = la.topic_progress_id
          WHERE aa.user_id = ?
       ORDER BY aa.attempt_id ASC',
        [$userId]
    );

    $responses = $rows(
        'SELECT ar.response_id, ar.attempt_id, ai.question_text, ar.user_answer,
                ai.correct_answer, ar.is_correct, ar.answered_at
           FROM attempt_response ar
           JOIN activity_attempt aa ON aa.attempt_id = ar.attempt_id
           JOIN activity_item ai    ON ai.item_id = ar.item_id
          WHERE aa.user_id = ?
       ORDER BY ar.response_id ASC',
        [$userId]
    );

    return [
        'exported_at' => date('c'),
        'system'      => 'EduFlex, a BSIT capstone prototype at the University of Cebu Main',
        'note'        => 'Mastery scores are computed by the weighted formula documented '
                       . 'in README section 4. The uploaded files themselves are not '
                       . 'included in this export, nor is your profile picture; you '
                       . 'already hold those. avatar_path names the picture on the '
                       . 'server so you can see that one is stored.',
        'account'     => $account[0] ?? null,
        'materials'   => $materials,
        'topics'      => $topics,
        'attempts'    => $attempts,
        'answers'     => $responses,
    ];
}

/**
 * Delete a learner and everything belonging to them.
 *
 * The learner must type their own email address. A single click is too easy to
 * make by accident for something no part of the system can undo.
 *
 * The foreign keys cascade, so one DELETE on `user` removes the materials,
 * chunks, topics, activities, attempts, responses, recommendations, chat
 * interactions, notifications and support requests. The uploaded files are
 * removed from disk first, because nothing points at them afterwards.
 *
 * @return array{ok:bool, error:?string, files:int}
 */
function auth_delete_account(int $userId, string $typedEmail): array
{
    $stmt = db()->prepare('SELECT email FROM user WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $email = $stmt->fetchColumn();

    if ($email === false) {
        return ['ok' => false, 'error' => 'That account no longer exists.', 'files' => 0];
    }

    if (strtolower(trim($typedEmail)) !== strtolower((string) $email)) {
        return [
            'ok'    => false,
            'error' => 'Type your own email address exactly to confirm the deletion.',
            'files' => 0,
        ];
    }

    // Collected before the delete; afterwards there is no row to read the
    // paths from and the files would be orphaned on disk forever.
    $stmt = db()->prepare('SELECT storage_path FROM learning_resource WHERE user_id = ?');
    $stmt->execute([$userId]);
    $paths = $stmt->fetchAll();

    // The profile picture is not in learning_resource, so it needs collecting
    // separately or it survives the account that owned it.
    $avatarPath = avatar_relative_path($userId);

    try {
        $stmt = db()->prepare('DELETE FROM user WHERE user_id = ?');
        $stmt->execute([$userId]);
    } catch (PDOException $e) {
        error_log('EduFlex account deletion failed: ' . $e->getMessage());
        return [
            'ok'    => false,
            'error' => 'The account could not be deleted. Tell the project team.',
            'files' => 0,
        ];
    }

    $removed = 0;
    foreach ($paths as $row) {
        $absolute = __DIR__ . '/../' . $row['storage_path'];
        if (is_file($absolute) && @unlink($absolute)) {
            $removed++;
        }
    }

    if ($avatarPath !== null) {
        avatar_unlink($avatarPath);
        $removed++;
    }

    return ['ok' => true, 'error' => null, 'files' => $removed];
}
