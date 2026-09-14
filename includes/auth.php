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

/**
 * Start the session with hardened cookie settings.
 * Safe to call on every page; it returns immediately if already started.
 */
function auth_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Cookie parameters can only be set before anything is output. If output
    // has already started (a stray blank line at the end of an include, or a
    // byte-order mark), skip the hardening rather than emitting a warning
    // into the middle of the page.
    if (!headers_sent()) {
        session_set_cookie_params([
            'lifetime' => 0,      // expires when the browser closes
            'path'     => '/',
            'httponly' => true,   // JavaScript cannot read the session cookie
            'samesite' => 'Lax',  // blocks the simple CSRF cases
            // Set 'secure' => true once the system is served over HTTPS.
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

    if (mb_strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
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

        // Welcome notification, so the dashboard is not completely empty.
        $stmt = $pdo->prepare(
            'INSERT INTO notification (user_id, notification_type, title, message)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            'system',
            'Welcome to EduFlex',
            'Upload a lecture note or reviewer to generate your first practice activity.',
        ]);

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
        return $generic;
    }

    if (!password_verify($password, $user['password_hash'])) {
        return $generic;
    }

    if ($user['account_status'] !== 'active') {
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
    return isset($_SESSION['user_id']);
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
