<?php
/**
 * EduFlex — hardening: response headers, error output, and login throttling.
 *
 * Week 7 work. Nothing here changes what the system does; it changes what it
 * gives away and how hard it is to attack. Each control below is here because
 * a specific thing was demonstrably possible without it, and each is asserted
 * in tests/security_test.php.
 *
 * Called from auth_boot(), which every page and every endpoint already runs, so
 * there is exactly one call site and no page can forget.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/* -------------------------------------------------------------------------
   Is this request over HTTPS?

   Read only from the server's own variables. X-Forwarded-Proto is a request
   header, so anybody can send it; trusting it would let a plain HTTP client
   claim to be secure and collect a cookie marked Secure.
   ------------------------------------------------------------------------- */

function security_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    // Set by the SAPI, not by the client.
    if (($_SERVER['REQUEST_SCHEME'] ?? '') === 'https') {
        return true;
    }
    return ((int) ($_SERVER['SERVER_PORT'] ?? 0)) === 443;
}

/**
 * The requesting address, for throttling.
 *
 * REMOTE_ADDR only, deliberately. X-Forwarded-For and X-Real-IP are request
 * headers under the caller's control: honouring them would let one attacker
 * defeat the login throttle entirely by sending a different fake address with
 * every attempt. If EduFlex is ever put behind a reverse proxy, this is the
 * one function to change, and only after the proxy is made to overwrite the
 * header rather than append to it.
 */
function security_client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return $ip === '' ? 'unknown' : mb_substr($ip, 0, 45);
}

/* -------------------------------------------------------------------------
   Response headers
   ------------------------------------------------------------------------- */

/**
 * Security headers for every response.
 *
 * The Content-Security-Policy is the interesting one. `default-src 'self'`
 * means the browser will refuse to load a script, stylesheet, font, image or
 * connection from any other origin, which turns "no external network requests"
 * from a rule in CLAUDE.md into something the browser enforces. If somebody
 * adds a CDN link by accident, it fails visibly in the console instead of
 * working on their machine and breaking in a defense room with no wifi.
 *
 * 'unsafe-inline' is granted for scripts and styles because the generated
 * pages carry inline <script> blocks and inline style attributes. That is a
 * real weakening and worth saying out loud: the policy blocks external code,
 * not injected inline code. Output escaping through e() is what stops injected
 * inline code, and that is tested separately.
 */
function security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    /* XAMPP ships with expose_php on, so every response announced
       "X-Powered-By: PHP/8.2.12". That tells an attacker exactly which
       published vulnerabilities to try. expose_php can only be turned off in
       php.ini, which is not in this repository, so the header is removed here
       instead and the fix travels with the code. */
    header_remove('X-Powered-By');

    foreach (security_header_list() as $name => $value) {
        header($name . ': ' . $value);
    }
}

/**
 * The headers as data, so the test suite can assert on them. header() is a
 * no-op under the CLI SAPI, so a test that called security_headers() and read
 * headers_list() back would pass while proving nothing.
 *
 * @return array<string,string>
 */
function security_header_list(): array
{
    $headers = [
        // Never let a browser guess a type. Stops an uploaded file that sneaks
        // past validation from being sniffed into something executable.
        'X-Content-Type-Options' => 'nosniff',

        // Clickjacking. The Android build loads these pages as a top-level
        // document in a WebView, not in a frame, so denying frames costs
        // nothing here.
        'X-Frame-Options' => 'DENY',

        // Do not leak the path a learner came from to any other site.
        'Referrer-Policy' => 'same-origin',

        // Nothing in EduFlex uses a camera, microphone or location.
        'Permissions-Policy' => 'geolocation=(), camera=(), microphone=()',

        'Content-Security-Policy' =>
            "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline'; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data:; "
            . "font-src 'self'; "
            . "connect-src 'self'; "
            . "form-action 'self'; "
            . "frame-ancestors 'none'; "
            . "base-uri 'self'; "
            . "object-src 'none'",
    ];

    // Only meaningful over HTTPS, and actively harmful otherwise: a browser
    // that sees it on a plain HTTP page may refuse the site later.
    if (security_is_https()) {
        $headers['Strict-Transport-Security'] = 'max-age=31536000';
    }

    return $headers;
}

/* -------------------------------------------------------------------------
   Error output

   includes/*.php are careful never to show a database error to a user, because
   the message leaks table and column names. XAMPP ships with display_errors
   on, which undoes that care: any uncaught warning prints the full server path
   and often part of a query straight into the page.
   ------------------------------------------------------------------------- */

/**
 * Log errors, never print them.
 *
 * The CLI is exempt, because the test suites need to see what went wrong.
 * Define EDUFLEX_DEBUG as true before including auth.php to get the old
 * behaviour back while developing.
 */
function security_harden_error_output(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    if (defined('EDUFLEX_DEBUG') && EDUFLEX_DEBUG === true) {
        return;
    }

    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

/* -------------------------------------------------------------------------
   Login throttling

   Without this, the login form accepts unlimited guesses at whatever rate the
   network allows, which is the only thing standing between a weak password and
   an account. Counted per email and per address, in SQL, because a counter in
   the session is defeated by not sending the cookie.
   ------------------------------------------------------------------------- */

/** How long a window of attempts is measured over. */
const LOGIN_WINDOW_MINUTES = 15;

/** Failures against ONE email from ONE address before that pair is refused. */
const LOGIN_MAX_PER_IDENTITY = 5;

/**
 * Failures from ONE address across ALL emails before the address is refused.
 * Higher than the per-identity limit, and it is what stops an attacker spraying
 * one common password across many accounts instead of many passwords at one.
 */
const LOGIN_MAX_PER_IP = 20;

/**
 * Is this email and address currently allowed to attempt a login?
 *
 * @return array{allowed:bool, retry_after:int} retry_after is in seconds
 */
function login_throttle_check(string $email, string $ip): array
{
    $allow = ['allowed' => true, 'retry_after' => 0];

    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) AS hits, MAX(attempted_at) AS latest
               FROM login_attempt
              WHERE email = ? AND ip_address = ?
                AND attempted_at > ?'
        );
        $stmt->execute([$email, $ip, login_throttle_window_start()]);
        $identity = $stmt->fetch() ?: [];

        if ((int) ($identity['hits'] ?? 0) >= LOGIN_MAX_PER_IDENTITY) {
            return [
                'allowed'     => false,
                'retry_after' => login_throttle_retry_after((string) ($identity['latest'] ?? '')),
            ];
        }

        $stmt = db()->prepare(
            'SELECT COUNT(*) AS hits, MAX(attempted_at) AS latest
               FROM login_attempt
              WHERE ip_address = ? AND attempted_at > ?'
        );
        $stmt->execute([$ip, login_throttle_window_start()]);
        $address = $stmt->fetch() ?: [];

        if ((int) ($address['hits'] ?? 0) >= LOGIN_MAX_PER_IP) {
            return [
                'allowed'     => false,
                'retry_after' => login_throttle_retry_after((string) ($address['latest'] ?? '')),
            ];
        }
    } catch (Throwable $e) {
        /* Fails OPEN, and this is a deliberate choice worth defending.
           The only realistic reason this query fails is that login_attempt is
           missing because somebody has not reimported the schema. Failing
           closed would mean nobody can sign in at all, which is a worse
           outcome for this project than a temporarily absent rate limit, and
           an attacker cannot cause this without database access. It is logged
           loudly so the cause is findable. */
        error_log('EduFlex login throttle unavailable, allowing the attempt: ' . $e->getMessage());
    }

    return $allow;
}

/**
 * Record one FAILED attempt. Successful logins are never recorded.
 *
 * attempted_at is supplied from PHP rather than left to the column default,
 * and this matters. SQLite's CURRENT_TIMESTAMP is UTC while PHP's date() is
 * local time, so on any machine not set to UTC the rows landed hours away from
 * the window this file compares them against and the throttle counted nothing.
 * The same mismatch appears on MySQL whenever PHP and the server disagree about
 * the timezone. Writing and comparing with one clock removes the whole class of
 * bug. The column keeps its DEFAULT as a safety net for anything that inserts
 * by hand.
 */
function login_throttle_record_failure(string $email, string $ip): void
{
    try {
        $stmt = db()->prepare(
            'INSERT INTO login_attempt (email, ip_address, attempted_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([mb_substr($email, 0, 255), $ip, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        error_log('EduFlex could not record a failed login: ' . $e->getMessage());
    }
}

/**
 * Clear the counter for one email and address after a successful login, so a
 * learner who mistyped four times is not punished once they get it right.
 */
function login_throttle_clear(string $email, string $ip): void
{
    try {
        $stmt = db()->prepare('DELETE FROM login_attempt WHERE email = ? AND ip_address = ?');
        $stmt->execute([$email, $ip]);
    } catch (Throwable $e) {
        error_log('EduFlex could not clear login attempts: ' . $e->getMessage());
    }
}

/**
 * Delete attempts older than the window.
 *
 * Called on a successful login rather than on a schedule, because there is no
 * scheduler. The table therefore stays small without a cron job.
 */
function login_throttle_prune(): void
{
    try {
        $stmt = db()->prepare('DELETE FROM login_attempt WHERE attempted_at < ?');
        $stmt->execute([login_throttle_window_start()]);
    } catch (Throwable $e) {
        error_log('EduFlex could not prune login attempts: ' . $e->getMessage());
    }
}

/**
 * The start of the current window, as a string both MySQL and SQLite compare
 * correctly against a DATETIME or TEXT column.
 */
function login_throttle_window_start(): string
{
    return date('Y-m-d H:i:s', time() - (LOGIN_WINDOW_MINUTES * 60));
}

/**
 * Seconds until the window clears, measured from the most recent failure.
 */
function login_throttle_retry_after(string $latest): int
{
    $window = LOGIN_WINDOW_MINUTES * 60;

    if (trim($latest) === '') {
        return $window;
    }
    $then = strtotime($latest);
    if ($then === false) {
        return $window;
    }

    return (int) max(1, min($window, ($then + $window) - time()));
}

/**
 * The message shown to a throttled attempt.
 *
 * This necessarily differs from "email or password is incorrect", so it does
 * reveal that somebody has been guessing at this address. That is the accepted
 * trade: the alternative is no limit at all.
 */
function login_throttle_message(int $retryAfter): string
{
    $minutes = (int) ceil(max(1, $retryAfter) / 60);

    return 'Too many failed sign-in attempts. Try again in '
         . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.';
}
