<?php
// Real authentication, isolated SQLite database, captured emails: no live data or SMTP.
declare(strict_types=1);
ob_start();
$sessionDir = sys_get_temp_dir() . '/eduflex-verification-' . bin2hex(random_bytes(8));
mkdir($sessionDir, 0700);
session_save_path($sessionDir);
register_shutdown_function(static function () use ($sessionDir): void {
    if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
    foreach (glob($sessionDir . '/sess_*') ?: [] as $file) { unlink($file); }
    rmdir($sessionDir);
});
require_once __DIR__ . '/../includes/auth.php';
auth_boot();
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('PRAGMA foreign_keys = ON');
db_set_connection($pdo);
$pdo->exec("CREATE TABLE user (user_id INTEGER PRIMARY KEY AUTOINCREMENT, full_name TEXT, email TEXT UNIQUE,
 password_hash TEXT, account_status TEXT DEFAULT 'active', program TEXT, year_level INTEGER, email_verified_at TEXT NULL,
 avatar_path TEXT NULL, is_admin INTEGER NOT NULL DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
 CREATE TABLE subscription (user_id INTEGER UNIQUE, plan_type TEXT, status TEXT);
 CREATE TABLE notification (user_id INTEGER, notification_type TEXT, title TEXT, message TEXT);
 CREATE TABLE login_attempt (email TEXT, ip_address TEXT, attempted_at TEXT);
 CREATE TABLE email_verification (user_id INTEGER PRIMARY KEY REFERENCES user(user_id) ON DELETE CASCADE,
 challenge_id TEXT, binding_hash TEXT, code_hash TEXT, credential_hash TEXT, expires_at INTEGER, attempts INTEGER,
 last_sent_at INTEGER, send_window_start INTEGER, sends_in_window INTEGER, blocked_until INTEGER, consumed INTEGER);
 CREATE TABLE trusted_browser (token_hash TEXT NOT NULL, user_id INTEGER NOT NULL REFERENCES user(user_id) ON DELETE CASCADE,
 credential_hash TEXT, created_at INTEGER, expires_at INTEGER, registration_pending INTEGER DEFAULT 0,
 PRIMARY KEY (token_hash, user_id));");
$GLOBALS['eduflex_test_mail_config'] = ['enabled' => true];
$outbox = []; $deliver = true; $passed = 0; $failed = 0;
$GLOBALS['eduflex_test_mail_sender'] = static function ($email, $code) use (&$outbox, &$deliver): bool {
 if ($deliver) { $outbox[] = compact('email', 'code'); } return $deliver;
};
function check(string $label, bool $condition): void {
 global $passed, $failed;
 if ($condition) { $passed++; echo "PASS $label\n"; } else { $failed++; echo "FAIL $label\n"; }
}
function challenge(int $uid): array {
 $stmt = db()->prepare('SELECT * FROM email_verification WHERE user_id = ?'); $stmt->execute([$uid]); return $stmt->fetch() ?: [];
}
function cooldown(int $uid): void {
 db()->prepare('UPDATE email_verification SET last_sent_at = ? WHERE user_id = ?')->execute([time() - 61, $uid]);
}
function fresh(int $uid): void {
 db()->prepare('DELETE FROM email_verification WHERE user_id = ?')->execute([$uid]); $_SESSION = []; $_COOKIE = [];
}
function code(): string { global $outbox; return $outbox[count($outbox) - 1]['code']; }

$r = auth_register('Learner', 'learner@gmail.com', 'valid-password-1', true, 'does-not-match');
check('mismatching confirmation prevents account creation', !$r['ok'] && isset($r['errors']['confirm_password'])
    && (int) $pdo->query('SELECT COUNT(*) FROM user')->fetchColumn() === 0);
$r = auth_register('Learner', 'learner@gmail.com', 'valid-password-1', true);
check('missing confirmation is refused server-side', !$r['ok'] && isset($r['errors']['confirm_password']));
$r = auth_register('Learner', 'learner@gmail.com', 'valid-password-1', true, 'valid-password-1'); $uid = (int) $r['user_id'];
check('registration succeeds without requiring a code', $r['ok'] && empty($r['verification_required']));
check('registration sends no email', $outbox === []);
check('registration does not sign in automatically', !auth_is_logged_in());
check('registration leaves email unverified', verification_user($uid)['email_verified_at'] === null);
$registrationToken = $_COOKIE[TRUSTED_BROWSER_COOKIE];
$registrationHash = hash('sha256', $registrationToken);
check('initial registration grant is browser-bound', trusted_browser_matches(verification_user($uid)));
$pdo->prepare('UPDATE trusted_browser SET created_at = created_at - 120, expires_at = expires_at - 120 WHERE token_hash = ?')
    ->execute([$registrationHash]);
check('wrong first password cannot activate registration grant', !auth_login('learner@gmail.com', 'wrong')['ok']);
check('first login needs only the correct password in registering browser', auth_login('learner@gmail.com', 'valid-password-1')['ok'] && auth_is_logged_in());
check('first login sends no code and does not verify email', $outbox === [] && verification_user($uid)['email_verified_at'] === null);
$firstExpiry = trusted_browser_expiry(verification_user($uid));
check('seven-day trust starts at first successful login', abs($firstExpiry - time() - 604800) <= 1);
check('repeated login does not renew initial trust', auth_login('learner@gmail.com', 'valid-password-1')['ok'] && trusted_browser_expiry(verification_user($uid)) === $firstExpiry);
$initialSession = $_SESSION;
$_SESSION['_email_grace_until'] = time();
check('unverified active session cannot outlive seven-day grace', !auth_is_logged_in());
$_SESSION = $initialSession; auth_boot();
check('unverified user may choose verification from settings', verification_begin($uid, 'profile')['ok'] && auth_is_logged_in());
check('profile verification sends email to registered address', count($outbox) === 1 && $outbox[0]['email'] === 'learner@gmail.com');
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start(); require __DIR__ . '/../verify_email.php'; $profileHtml = ob_get_clean();
check('signed-in profile verification renders without redirecting away', str_contains($profileHtml, '<h2>Verify your email</h2>') && auth_is_logged_in());
check('optional verification offers return to settings and no trust renewal', str_contains($profileHtml, 'Return to settings') && !str_contains($profileHtml, 'name="trust_browser"'));
check('code is six digits', (bool) preg_match('/^\d{6}$/D', code()));
check('new email starts unverified', verification_user($uid)['email_verified_at'] === null);
check('code stored only as a password hash', challenge($uid)['code_hash'] !== code() && password_verify(code(), challenge($uid)['code_hash']));
check('pending session contains no code', !str_contains(json_encode($_SESSION), '"code"'));
check('code expires after ten minutes', abs((int) challenge($uid)['expires_at'] - time() - 600) <= 1);
check('resend has a sixty-second cooldown', !verification_send()['ok'] && count($outbox) === 1);
check('malformed profile code leaves email unverified', !verification_finish('abcdef', false)['ok'] && verification_user($uid)['email_verified_at'] === null);
check('incorrect code increments guessing counter', (int) challenge($uid)['attempts'] === 1);
$oldCode = code(); cooldown($uid); check('resend works after cooldown', verification_send()['ok']);
check('resend does not reset guesses', (int) challenge($uid)['attempts'] === 1);
if ($oldCode !== code()) { check('previous code is invalidated', !verification_finish($oldCode, false)['ok']); }
$oldPending = $_SESSION['_email_pending']; $r = verification_finish(code(), true);
check('optional profile verification succeeds', $r['ok'] && $r['purpose'] === 'profile' && $r['next'] === 'app/settings.php');
check('email ownership is recorded', verification_user($uid)['email_verified_at'] !== null);
check('profile verification keeps existing session', auth_is_logged_in() && $_SESSION['user_id'] === $uid
    && $_SESSION['logged_in_at'] === $initialSession['logged_in_at']);
check('profile verification does not extend browser trust', trusted_browser_expiry(verification_user($uid)) === $firstExpiry);
check('anonymous users cannot start profile verification', (function () use ($uid): bool {
    $session = $_SESSION; $_SESSION = [];
    $result = verification_begin($uid, 'profile'); $_SESSION = $session;
    return !$result['ok'];
})());
check('code is single use', (int) challenge($uid)['consumed'] === 1);
check('pending state is cleared', !isset($_SESSION['_email_pending']));
check('trust option creates a token', (int) $pdo->query('SELECT COUNT(*) FROM trusted_browser')->fetchColumn() === 1);
$_SESSION = ['_email_pending' => $oldPending];
check('replayed code is refused', !verification_finish(code(), false)['ok'] && !auth_is_logged_in());

$user = verification_user($uid); $token = trusted_browser_store($user); $_COOKIE[TRUSTED_BROWSER_COOKIE] = $token;
check('cookie identifies the trusted browser', trusted_browser_matches($user));
$stmt = $pdo->prepare('SELECT * FROM trusted_browser WHERE token_hash = ?'); $stmt->execute([hash('sha256', $token)]); $stored = $stmt->fetch();
check('raw browser token is not stored', $stored['token_hash'] !== $token && strlen($stored['token_hash']) === 64);
check('browser trust lasts exactly seven days', (int) $stored['expires_at'] - (int) $stored['created_at'] === 604800);
$count = count($outbox); $_SESSION = [];
check('trust never bypasses the password', !auth_login('learner@gmail.com', 'wrong-password')['ok'] && !auth_is_logged_in());
check('trusted browser permits password-only login', auth_login('learner@gmail.com', 'valid-password-1')['ok'] && auth_is_logged_in());
check('trusted sign-in sends no code', count($outbox) === $count);
$stmt->execute([hash('sha256', $token)]);
check('ordinary sign-in does not renew trust', (int) $stmt->fetch()['expires_at'] === (int) $stored['expires_at']);
$_COOKIE[TRUSTED_BROWSER_COOKIE] = str_repeat('f', 64); check('forged cookie is refused', !trusted_browser_matches($user));
$_COOKIE[TRUSTED_BROWSER_COOKIE] = [$token]; check('array-valued cookie is refused', !trusted_browser_matches($user));
unset($_COOKIE[TRUSTED_BROWSER_COOKIE]); check('new browser is untrusted', !trusted_browser_matches($user));
$_COOKIE[TRUSTED_BROWSER_COOKIE] = $token;
$pdo->prepare('UPDATE trusted_browser SET expires_at = ? WHERE token_hash = ?')->execute([time(), hash('sha256', $token)]);
check('trust expires at seven-day boundary', !trusted_browser_matches($user));
fresh($uid); $r = auth_login('learner@gmail.com', 'valid-password-1');
check('untrusted sign-in requires verification', !$r['ok'] && $r['verification_required'] && !auth_is_logged_in());
check('pending sign-in cannot access the application', !isset($_SESSION['user_id']));
$trustCount = (int) $pdo->query('SELECT COUNT(*) FROM trusted_browser')->fetchColumn();
check('sign-in code establishes session', verification_finish(code(), false)['ok'] && auth_is_logged_in());
check('unchecked trust does not add a browser token', (int) $pdo->query('SELECT COUNT(*) FROM trusted_browser')->fetchColumn() === $trustCount);

fresh($uid); auth_login('learner@gmail.com', 'valid-password-1'); $pending = $_SESSION['_email_pending'];
$_SESSION['_email_pending']['nonce'] = bin2hex(random_bytes(32));
check('code is bound to requesting session', !verification_finish(code(), true)['ok']); $_SESSION['_email_pending'] = $pending;
$pdo->prepare('UPDATE email_verification SET expires_at = ? WHERE user_id = ?')->execute([time(), $uid]);
check('code expires at ten-minute boundary', !verification_finish(code(), false)['ok']);
cooldown($uid); verification_send(); $wrong = code() === '000000' ? '111111' : '000000';
for ($i = 0; $i < 5; $i++) { verification_finish($wrong, false); }
check('five guesses lock verification', (int) challenge($uid)['attempts'] === 5 && (int) challenge($uid)['blocked_until'] > time());
check('correct code cannot bypass lockout', !verification_finish(code(), true)['ok']); cooldown($uid);
check('resend cannot bypass lockout', !verification_send()['ok']);
$pdo->prepare('UPDATE email_verification SET blocked_until = ? WHERE user_id = ?')->execute([time() - 1, $uid]);
check('resend works after lockout expires', verification_send()['ok']);
check('guesses reset after lockout', (int) challenge($uid)['attempts'] === 0);
fresh($uid); verification_begin($uid, 'login');
for ($i = 1; $i < 5; $i++) { cooldown($uid); verification_send(); }
check('hourly quota allows five emails', (int) challenge($uid)['sends_in_window'] === 5); cooldown($uid);
check('sixth email is refused', !verification_send()['ok']);
$pdo->prepare('UPDATE email_verification SET send_window_start = ? WHERE user_id = ?')->execute([time() - 3601, $uid]);
check('hourly quota resets', verification_send()['ok'] && (int) challenge($uid)['sends_in_window'] === 1);
$_SESSION['_email_pending']['created_at'] = time() - 1800;
check('pending sign-in expires after thirty minutes', verification_pending() === null && !verification_finish(code(), true)['ok']);

fresh($uid); $deliver = false; $r = auth_login('learner@gmail.com', 'valid-password-1');
check('delivery failure cannot sign in', !$r['ok'] && !$r['mail_sent'] && !auth_is_logged_in());
check('undelivered code is invalid', (int) challenge($uid)['consumed'] === 1);
check('delivery failure leaves retryable state', verification_pending() !== null);
$deliver = true; cooldown($uid); check('email can be retried', verification_send()['ok']);
$pdo->prepare("UPDATE user SET account_status = 'suspended' WHERE user_id = ?")->execute([$uid]);
check('suspension after password blocks code completion', !verification_finish(code(), true)['ok']);
$pdo->prepare("UPDATE user SET account_status = 'active' WHERE user_id = ?")->execute([$uid]);
fresh($uid); verification_begin($uid, 'login');
$pdo->prepare("UPDATE user SET email = 'changed@gmail.com' WHERE user_id = ?")->execute([$uid]);
check('email change invalidates pending code', !verification_finish(code(), true)['ok']);
$pdo->prepare("UPDATE user SET email = 'learner@gmail.com' WHERE user_id = ?")->execute([$uid]);
fresh($uid); verification_begin($uid, 'login'); $token = trusted_browser_store(verification_user($uid));
$_COOKIE[TRUSTED_BROWSER_COOKIE] = $token;
check('password change succeeds', auth_change_password($uid, 'valid-password-1', 'new-password-22', 'new-password-22')['ok']);
check('password change revokes all browsers', (int) $pdo->query('SELECT COUNT(*) FROM trusted_browser')->fetchColumn() === 0);
check('password change revokes codes', challenge($uid) === []);
check('stale code cannot complete sign-in', !verification_finish(code(), true)['ok']);
fresh($uid); auth_login('learner@gmail.com', 'new-password-22'); $_SESSION['_email_pending']['next'] = 'https://evil.example/phish';
$r = verification_finish(code(), true); check('redirect is validated after code', $r['ok'] && $r['next'] === 'app/dashboard.php');
// Exercise the actual verification page with an unauthenticated pending session.
fresh($uid); verification_begin($uid, 'login');
$_SERVER['REQUEST_METHOD'] = 'GET';
ob_start(); require __DIR__ . '/../verify_email.php'; $html = ob_get_clean();
check('verification page renders the code input', str_contains($html, 'autocomplete="one-time-code"'));
check('verification page masks the recipient', str_contains($html, 'l***@gmail.com') && !str_contains($html, 'learner@gmail.com'));
check('verification page never renders the code', !str_contains($html, code()));
check('verification form carries the real CSRF token', str_contains($html, e(csrf_token())));
check('browser trust is opt-in', str_contains($html, 'name="trust_browser"') && !str_contains($html, 'value="1" checked'));
$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST = ['code' => code(), '_csrf' => 'invalid', 'action' => 'verify'];
ob_start(); require __DIR__ . '/../verify_email.php'; $html = ob_get_clean();
check('endpoint refuses a correct code without CSRF', !auth_is_logged_in() && str_contains($html, 'Your session expired'));
check('CSRF rejection does not consume the code', (int) challenge($uid)['consumed'] === 0);
check('correct CSRF-protected code can still complete', verification_finish(code(), false)['ok']);
$oldSession = $_SESSION;
check('current browser stays signed in after password change',
    auth_change_password($uid, 'new-password-22', 'final-password-333', 'final-password-333')['ok'] && auth_is_logged_in());
$newSession = $_SESSION; $_SESSION = $oldSession;
check('password change invalidates another older session', !auth_is_logged_in());
$_SESSION = $newSession; auth_boot();
$pdo->prepare("UPDATE user SET email = 'newaddress@gmail.com', email_verified_at = NULL WHERE user_id = ?")->execute([$uid]);
check('email change invalidates an active session', !auth_is_logged_in());
$pdo->prepare('DELETE FROM user WHERE user_id = ?')->execute([$uid]);
check('account deletion removes codes', (int) $pdo->query('SELECT COUNT(*) FROM email_verification')->fetchColumn() === 0);
check('account deletion removes browser tokens', (int) $pdo->query('SELECT COUNT(*) FROM trusted_browser')->fetchColumn() === 0);

// An account that defers optional verification must verify after its initial trust expires.
$_SESSION = []; $_COOKIE = [];
$r = auth_register('Deferred Learner', 'deferred@gmail.com', 'deferred-password-1', true, 'deferred-password-1');
$deferredId = (int) $r['user_id'];
check('deferred account can sign in during initial grace', auth_login('deferred@gmail.com', 'deferred-password-1')['ok']
    && verification_user($deferredId)['email_verified_at'] === null);
$cookie = $_COOKIE[TRUSTED_BROWSER_COOKIE];
$pdo->prepare('UPDATE trusted_browser SET expires_at = ? WHERE user_id = ?')->execute([time(), $deferredId]);
$_SESSION = []; $_COOKIE[TRUSTED_BROWSER_COOKIE] = $cookie;
$r = auth_login('deferred@gmail.com', 'deferred-password-1');
check('expired initial trust blocks unverified password-only login', !$r['ok'] && $r['verification_required'] && !auth_is_logged_in());
check('expired grace sends a code to confirm email ownership', end($outbox)['email'] === 'deferred@gmail.com');
check('correct code verifies deferred email and permits sign-in', verification_finish(code(), true)['ok'] && auth_is_logged_in()
    && verification_user($deferredId)['email_verified_at'] !== null);
$stmt = $pdo->prepare('SELECT registration_pending FROM trusted_browser WHERE token_hash = ?');
$stmt->execute([hash('sha256', $_COOKIE[TRUSTED_BROWSER_COOKIE])]);
check('renewed trust is not another registration exception', (int) $stmt->fetchColumn() === 0);
$pdo->prepare('DELETE FROM user WHERE user_id = ?')->execute([$deferredId]);

/* ------------------------------------------- one browser, two accounts */

echo "
-- one browser, two accounts --
";

/* 2 November 2026. Patrick reported that verification appeared on every login
   even after ticking "trust this browser for 7 days".
   
   trusted_browser was keyed on token_hash ALONE, and the cookie holds one
   token. Trusting a second account minted a new token and overwrote the
   cookie: the first account's row stayed in the table but the browser no
   longer held its token, so every later sign-in as that account asked for a
   code again. Trust one, lose the other, alternating forever.
   
   Reproduced with two accounts in one cookie jar: the first was challenged on
   every single login, the second never. His table had four unreachable rows
   for one account and three for another.
   
   The fix is in two halves and BOTH are needed: the key widened to
   (token_hash, user_id), and trusted_browser_store() reuses this browser's
   existing token instead of minting a new one. */

/* Both created here. The checks above delete users as part of testing
   withdrawal, so whatever is left in the table by this point is not something
   to build on. */
$pdo->exec("INSERT INTO user (full_name, email, password_hash, account_status)
            VALUES ('First Person', 'first-browser@example.com', 'x', 'active')");
$alice = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO user (full_name, email, password_hash, account_status)
            VALUES ('Second Person', 'second-browser@example.com', 'x', 'active')");
$bob = (int) $pdo->lastInsertId();

$_COOKIE = [];
$tokenA = trusted_browser_store(verification_user($alice));
$_COOKIE[TRUSTED_BROWSER_COOKIE] = $tokenA;

check('the first account is trusted',
    trusted_browser_matches(verification_user($alice)));

$tokenB = trusted_browser_store(verification_user($bob));

check('the second account reuses the same browser token', $tokenA === $tokenB);

$_COOKIE[TRUSTED_BROWSER_COOKIE] = $tokenB;

check('the second account is now trusted',
    trusted_browser_matches(verification_user($bob)));

check('and the FIRST is still trusted, which is the whole bug',
    trusted_browser_matches(verification_user($alice)));

check('both rows exist against one token', (function () use ($pdo, $tokenA) {
    $n = (int) $pdo->query("SELECT COUNT(*) FROM trusted_browser WHERE token_hash = '"
        . hash('sha256', $tokenA) . "'")->fetchColumn();
    return $n === 2;
})());

check('the key allows it, so the row could be stored at all', (function () use ($pdo) {
    // Keyed on token_hash alone, the second INSERT fails and registration
    // reports "Could not create the account."
    $sql = (string) file_get_contents(__DIR__ . '/../database/01_schema.sql');
    return (bool) preg_match('/PRIMARY KEY \(token_hash, user_id\)/', $sql);
})());

check('a browser with no cookie still gets a fresh token', (function () use ($alice) {
    $_COOKIE = [];
    $fresh = trusted_browser_store(verification_user($alice));
    return (bool) preg_match('/^[a-f0-9]{64}$/D', $fresh);
})());

check('a junk cookie is not reused as a token', (function () use ($bob) {
    $_COOKIE[TRUSTED_BROWSER_COOKIE] = 'not-a-token';
    $fresh = trusted_browser_store(verification_user($bob));
    return $fresh !== 'not-a-token'
        && (bool) preg_match('/^[a-f0-9]{64}$/D', $fresh);
})());

check('declining to trust does not wipe the browser token', (function () {
    // The other half of the same bug: clearing the cookie on "do not trust me"
    // threw away the token and with it every OTHER account's trust here.
    $src = php_strip_whitespace(__DIR__ . '/../includes/verification.php');
    return !str_contains($src, "trusted_browser_cookie('', time() - 3600)");
})());

echo "\nPassed: $passed   Failed: $failed\n"; ob_end_flush(); exit($failed ? 1 : 0);
