<?php
/**
 * EduFlex — hardening test suite.
 *
 *     php tests/security_test.php
 *
 * Runs against an in-memory database. No API key, no network, no server.
 *
 * Two of these checks exist because the behaviour they forbid was demonstrated
 * against the running system first:
 *
 *   The login form's "next" parameter accepted `/\evil.example.com/phish` and
 *   returned it in a Location header. Browsers read a slash followed by a
 *   backslash as two slashes, so that was a working open redirect: sign in
 *   legitimately, land somewhere else. Every bypass in the table below is
 *   asserted, not just that one.
 *
 *   The login form accepted unlimited password guesses. The throttle now counts
 *   failures per email and per address, and counts them for emails that do not
 *   exist so the throttle cannot itself be used to discover which do.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "  PASS  $label\n"; }
    else            { $failed++; echo "  FAIL  $label\n"; }
}
function section(string $name): void { echo "\n$name\n"; }

echo "EduFlex hardening suite\n=======================\n";

/* ----------------------------------------------------------- test database */

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("
CREATE TABLE user (
  user_id INTEGER PRIMARY KEY AUTOINCREMENT, full_name TEXT NOT NULL,
  email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL,
  program TEXT NOT NULL DEFAULT '', year_level INTEGER NOT NULL DEFAULT 1,
  account_status TEXT NOT NULL DEFAULT 'active',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE subscription (
  subscription_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
  plan_type TEXT NOT NULL, status TEXT NOT NULL);
CREATE TABLE notification (
  notification_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
  notification_type TEXT NOT NULL, title TEXT NOT NULL, message TEXT NOT NULL,
  is_read INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE login_attempt (
  attempt_id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL,
  ip_address TEXT NOT NULL,
  attempted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
");
db_set_connection($pdo);

$pdo->prepare('INSERT INTO user (full_name, email, password_hash) VALUES (?, ?, ?)')
    ->execute(['Real Learner', 'real@example.com', password_hash('the right password', PASSWORD_DEFAULT)]);

/* ------------------------------------------------------------ open redirect */

section('The login redirect accepts only same-site paths');

/* label => [candidate, should it be allowed] */
$cases = [
    'a relative app path'            => ['app/dashboard.php', true],
    'an absolute same-site path'     => ['/eduflex-ui/app/practice.php', true],
    'a path with a query string'     => ['app/practice_run.php?attempt=7', true],
    'a bare filename'                => ['settings.php', true],

    'protocol-relative'              => ['//evil.example.com/x', false],
    'an explicit http URL'           => ['http://evil.example.com/x', false],
    'an explicit https URL'          => ['https://evil.example.com/x', false],
    'slash-backslash (was live)'     => ['/\\evil.example.com/phish', false],
    'backslash-backslash'            => ['\\\\evil.example.com/x', false],
    'escaped double slash'           => ['\\/\\/evil.example.com/x', false],
    'http with a backslash'          => ['http:/\\evil.example.com/x', false],
    'an encoded slash'               => ['/%2Fevil.example.com', false],
    'an encoded backslash'           => ['/%5Cevil.example.com', false],
    'a javascript scheme'            => ['javascript:alert(1)', false],
    'a data scheme'                  => ['data:text/html,<script>1</script>', false],
    'a leading space'                => [' //evil.example.com', false],
    'a leading tab'                  => ["\t//evil.example.com", false],
    'an embedded newline'            => ["app/dashboard.php\nSet-Cookie: x=1", false],
    'an embedded null'               => ["app/dashboard.php\0.evil", false],
    'path traversal'                 => ['../../../etc/passwd', false],
    'traversal out of the app'       => ['app/../../secret.php', false],
    'an empty string'                => ['', false],
    'null'                           => [null, false],
];

foreach ($cases as $label => [$candidate, $shouldAllow]) {
    $result  = auth_safe_redirect_target($candidate);
    $allowed = $result === $candidate;
    check($label . ($shouldAllow ? ' is followed' : ' is refused'), $allowed === $shouldAllow);
}

check('a refused value falls back to the dashboard',
    auth_safe_redirect_target('//evil.example.com') === 'app/dashboard.php');
check('the fallback can be overridden',
    auth_safe_redirect_target('//evil.example.com', '') === '');
check('the old check would have allowed the live bypass, this one does not',
    !preg_match('#^(https?:)?//#', '/\\evil.example.com/phish')
    && auth_safe_redirect_target('/\\evil.example.com/phish') !== '/\\evil.example.com/phish');

/* ------------------------------------------------------------------ headers */

section('Security headers');

$headers = security_header_list();

check('nosniff is sent',            ($headers['X-Content-Type-Options'] ?? '') === 'nosniff');
check('framing is denied',          ($headers['X-Frame-Options'] ?? '') === 'DENY');
check('the referrer is kept same-origin',
    ($headers['Referrer-Policy'] ?? '') === 'same-origin');
check('a CSP is sent',              isset($headers['Content-Security-Policy']));

$csp = (string) ($headers['Content-Security-Policy'] ?? '');
check("the CSP defaults to 'self'", str_contains($csp, "default-src 'self'"));
check('the CSP forbids plugins',    str_contains($csp, "object-src 'none'"));
check('the CSP forbids framing',    str_contains($csp, "frame-ancestors 'none'"));
check('the CSP pins form targets',  str_contains($csp, "form-action 'self'"));
check('the CSP names no external origin', !preg_match('#https?://#', $csp));

/* The rule in CLAUDE.md is that EduFlex makes no external network request.
   default-src 'self' is what makes the browser enforce it rather than trusting
   everyone to remember. */
check('no vendored asset host is allowlisted', (function () use ($csp) {
    foreach (['cdn', 'googleapis', 'gstatic', 'jsdelivr', 'unpkg', 'cloudflare'] as $host) {
        if (str_contains($csp, $host)) { return false; }
    }
    return true;
})());

check('HSTS is NOT sent over plain HTTP', !isset($headers['Strict-Transport-Security']));
check('HSTS is sent over HTTPS', (function () {
    $_SERVER['HTTPS'] = 'on';
    $sent = isset(security_header_list()['Strict-Transport-Security']);
    unset($_SERVER['HTTPS']);
    return $sent;
})());

section('HTTPS detection ignores anything the client controls');

check('plain HTTP is detected as plain', security_is_https() === false);
check('HTTPS=on is honoured', (function () {
    $_SERVER['HTTPS'] = 'on';
    $r = security_is_https();
    unset($_SERVER['HTTPS']);
    return $r === true;
})());
check('HTTPS=off is not mistaken for on', (function () {
    $_SERVER['HTTPS'] = 'off';
    $r = security_is_https();
    unset($_SERVER['HTTPS']);
    return $r === false;
})());
check('a forged X-Forwarded-Proto header does not make it HTTPS', (function () {
    // If this passed, a plain HTTP client could get a cookie marked Secure.
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    $r = security_is_https();
    unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
    return $r === false;
})());

section('The client address is the one the server saw');

check('REMOTE_ADDR is used', (function () {
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $r = security_client_ip();
    return $r === '203.0.113.9';
})());
check('a forged X-Forwarded-For is ignored', (function () {
    // If this passed, one attacker would defeat the throttle entirely by
    // sending a different fake address with every guess.
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.1';
    $r = security_client_ip();
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    return $r === '203.0.113.9';
})());
check('a missing address does not become an empty key', (function () {
    $saved = $_SERVER['REMOTE_ADDR'] ?? null;
    unset($_SERVER['REMOTE_ADDR']);
    $r = security_client_ip();
    if ($saved !== null) { $_SERVER['REMOTE_ADDR'] = $saved; }
    return $r === 'unknown';
})());

/* --------------------------------------------------------- login throttling */

section('The throttle counts failures per email and address');

$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$pdo->exec('DELETE FROM login_attempt');

check('a fresh identity is allowed',
    login_throttle_check('real@example.com', '203.0.113.9')['allowed'] === true);

// One short of the limit.
for ($i = 0; $i < LOGIN_MAX_PER_IDENTITY - 1; $i++) {
    login_throttle_record_failure('real@example.com', '203.0.113.9');
}
check('under the limit it is still allowed',
    login_throttle_check('real@example.com', '203.0.113.9')['allowed'] === true);

login_throttle_record_failure('real@example.com', '203.0.113.9');
$blocked = login_throttle_check('real@example.com', '203.0.113.9');
check('at the limit it is refused',   $blocked['allowed'] === false);
check('and it says how long to wait', $blocked['retry_after'] > 0);
check('the wait never exceeds the window',
    $blocked['retry_after'] <= LOGIN_WINDOW_MINUTES * 60);
check('the message names the minutes',
    str_contains(login_throttle_message($blocked['retry_after']), 'minute'));

check('a different email from the same address is still allowed',
    login_throttle_check('someone.else@example.com', '203.0.113.9')['allowed'] === true);
check('the same email from a different address is still allowed',
    login_throttle_check('real@example.com', '198.51.100.7')['allowed'] === true);

section('A successful login clears the counter');

check('the blocked identity is refused by auth_login',
    str_contains(auth_login('real@example.com', 'the right password')['errors']['form'] ?? '', 'Too many'));

login_throttle_clear('real@example.com', '203.0.113.9');
check('clearing removes only that identity',
    (int) $pdo->query("SELECT COUNT(*) FROM login_attempt
                        WHERE email = 'real@example.com'")->fetchColumn() === 0);

$ok = auth_login('real@example.com', 'the right password');
check('the right password now works',        $ok['ok'] === true);
check('a successful login records nothing',
    (int) $pdo->query('SELECT COUNT(*) FROM login_attempt')->fetchColumn() === 0);

check('four wrong tries then the right one leaves no counter behind', (function () use ($pdo) {
    for ($i = 0; $i < 4; $i++) {
        auth_login('real@example.com', 'wrong password');
    }
    $before = (int) $pdo->query('SELECT COUNT(*) FROM login_attempt')->fetchColumn();
    $good   = auth_login('real@example.com', 'the right password');
    $after  = (int) $pdo->query('SELECT COUNT(*) FROM login_attempt')->fetchColumn();
    return $before === 4 && $good['ok'] === true && $after === 0;
})());

section('The throttle is not an account-existence oracle');

$pdo->exec('DELETE FROM login_attempt');

check('a failure against an unknown email is counted too', (function () use ($pdo) {
    auth_login('nobody@example.com', 'any password at all');
    return (int) $pdo->query("SELECT COUNT(*) FROM login_attempt
                              WHERE email = 'nobody@example.com'")->fetchColumn() === 1;
})());

check('an unknown email is refused at the same limit as a real one', (function () {
    for ($i = 0; $i < LOGIN_MAX_PER_IDENTITY; $i++) {
        auth_login('nobody@example.com', 'any password at all');
    }
    return str_contains(auth_login('nobody@example.com', 'x')['errors']['form'] ?? '', 'Too many');
})());

check('an inactive account still counts against the limit', (function () use ($pdo) {
    $pdo->prepare("INSERT INTO user (full_name, email, password_hash, account_status)
                   VALUES (?, ?, ?, 'suspended')")
        ->execute(['Suspended', 'suspended@example.com', password_hash('the right password', PASSWORD_DEFAULT)]);
    $pdo->exec("DELETE FROM login_attempt WHERE email = 'suspended@example.com'");
    $r = auth_login('suspended@example.com', 'the right password');
    $counted = (int) $pdo->query("SELECT COUNT(*) FROM login_attempt
                                  WHERE email = 'suspended@example.com'")->fetchColumn();
    return $r['ok'] === false && $counted === 1;
})());

section('One address cannot spray many accounts');

$pdo->exec('DELETE FROM login_attempt');

// Well under the per-identity limit for each email, but over the per-address one.
for ($i = 0; $i < LOGIN_MAX_PER_IP; $i++) {
    login_throttle_record_failure('target' . $i . '@example.com', '203.0.113.9');
}
check('the address is refused once it is over the spray limit',
    login_throttle_check('a.brand.new@example.com', '203.0.113.9')['allowed'] === false);
check('another address is unaffected',
    login_throttle_check('a.brand.new@example.com', '198.51.100.7')['allowed'] === true);
check('the per-address limit is looser than the per-identity one',
    LOGIN_MAX_PER_IP > LOGIN_MAX_PER_IDENTITY);

section('Old attempts stop counting');

$pdo->exec('DELETE FROM login_attempt');
$stale = date('Y-m-d H:i:s', time() - ((LOGIN_WINDOW_MINUTES + 5) * 60));
for ($i = 0; $i < LOGIN_MAX_PER_IDENTITY + 3; $i++) {
    $pdo->prepare('INSERT INTO login_attempt (email, ip_address, attempted_at) VALUES (?, ?, ?)')
        ->execute(['real@example.com', '203.0.113.9', $stale]);
}
check('attempts older than the window do not block',
    login_throttle_check('real@example.com', '203.0.113.9')['allowed'] === true);

check('pruning removes them', (function () use ($pdo) {
    login_throttle_prune();
    return (int) $pdo->query('SELECT COUNT(*) FROM login_attempt')->fetchColumn() === 0;
})());

check('pruning keeps attempts inside the window', (function () use ($pdo) {
    login_throttle_record_failure('real@example.com', '203.0.113.9');
    login_throttle_prune();
    return (int) $pdo->query('SELECT COUNT(*) FROM login_attempt')->fetchColumn() === 1;
})());

section('The throttle fails open rather than locking everyone out');

/* A deliberate trade, documented in includes/security.php: the only realistic
   cause is a missing table, and refusing every login is worse for this project
   than a temporarily absent rate limit. */
$pdo->exec('DROP TABLE login_attempt');

check('a missing table does not block a login',
    login_throttle_check('real@example.com', '203.0.113.9')['allowed'] === true);
check('recording a failure does not throw', (function () {
    login_throttle_record_failure('real@example.com', '203.0.113.9');
    return true;
})());
check('clearing does not throw', (function () {
    login_throttle_clear('real@example.com', '203.0.113.9');
    return true;
})());
check('pruning does not throw', (function () {
    login_throttle_prune();
    return true;
})());
check('a correct password still signs in',
    auth_login('real@example.com', 'the right password')['ok'] === true);
check('a wrong password is still refused',
    auth_login('real@example.com', 'the wrong password')['ok'] === false);

/* ---------------------------------------------------------- session expiry */

section('Sessions expire');

check('a session with no timestamps is treated as expired', (function () {
    $_SESSION = ['user_id' => 1];
    return auth_session_expired() === true;
})());

check('a fresh session is live', (function () {
    $_SESSION = ['user_id' => 1, 'logged_in_at' => time(), 'last_seen_at' => time()];
    return auth_session_expired() === false;
})());

check('a session idle past the limit is expired', (function () {
    $_SESSION = [
        'user_id'      => 1,
        'logged_in_at' => time() - 600,
        'last_seen_at' => time() - (SESSION_IDLE_SECONDS + 60),
    ];
    return auth_session_expired() === true;
})());

check('a session idle just under the limit is live', (function () {
    $_SESSION = [
        'user_id'      => 1,
        'logged_in_at' => time() - 600,
        'last_seen_at' => time() - (SESSION_IDLE_SECONDS - 60),
    ];
    return auth_session_expired() === false;
})());

check('a session kept busy past the absolute limit is still expired', (function () {
    // This is the case an idle timeout alone would miss: a stolen cookie that
    // is used constantly never goes idle.
    $_SESSION = [
        'user_id'      => 1,
        'logged_in_at' => time() - (SESSION_ABSOLUTE_SECONDS + 60),
        'last_seen_at' => time(),
    ];
    return auth_session_expired() === true;
})());

check('the absolute limit is longer than the idle one',
    SESSION_ABSOLUTE_SECONDS > SESSION_IDLE_SECONDS);

$_SESSION = [];

echo "\n=======================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
