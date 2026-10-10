<?php
/** Email ownership and seven-day browser trust. No passwordless sign-in. */
declare(strict_types=1);
require_once __DIR__ . '/mail.php';

const VERIFICATION_CODE_SECONDS = 600;
const VERIFICATION_PENDING_SECONDS = 1800;
const VERIFICATION_RESEND_SECONDS = 60;
const VERIFICATION_MAX_SENDS = 5;
const VERIFICATION_MAX_GUESSES = 5;
const VERIFICATION_LOCK_SECONDS = 900;
const TRUSTED_BROWSER_SECONDS = 604800;
const TRUSTED_BROWSER_COOKIE = 'eduflex_browser';

function verification_user(int $userId, bool $lock = false): ?array
{
    $sql = 'SELECT user_id, full_name, email, password_hash, account_status, email_verified_at
              FROM user WHERE user_id = ?';
    if ($lock && db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $sql .= ' FOR UPDATE';
    }
    $stmt = db()->prepare($sql);
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function verification_credential(array $user): string
{
    return hash('sha256', $user['email'] . "\0" . $user['password_hash']);
}

function verification_pending(): ?array
{
    $pending = $_SESSION['_email_pending'] ?? null;
    if (!is_array($pending) || time() - (int) ($pending['created_at'] ?? 0) >= VERIFICATION_PENDING_SECONDS) {
        unset($_SESSION['_email_pending']);
        return null;
    }
    return $pending;
}

function verification_begin(int $userId, string $purpose): array
{
    auth_boot();
    if (!in_array($purpose, ['login', 'profile'], true)
        || ($purpose === 'profile' && (!auth_is_logged_in() || (int) $_SESSION['user_id'] !== $userId))) {
        return ['ok' => false, 'error' => 'Please sign in again.'];
    }
    if ($purpose === 'login') {
        unset($_SESSION['user_id'], $_SESSION['full_name'], $_SESSION['email'],
            $_SESSION['logged_in_at'], $_SESSION['last_seen_at'], $_SESSION['_credential_hash'], $_SESSION['_email_grace_until']);
    }
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
    $user = verification_user($userId);
    if (!$user || $user['account_status'] !== 'active') {
        unset($_SESSION['_email_pending']);
        return ['ok' => false, 'error' => 'Please sign in again.'];
    }
    $_SESSION['_email_pending'] = [
        'user_id' => $userId, 'purpose' => $purpose,
        'nonce' => bin2hex(random_bytes(32)), 'created_at' => time(),
        'credential_hash' => verification_credential($user),
        'next' => $purpose === 'profile' ? 'app/settings.php' : 'app/dashboard.php', 'mail_sent' => false,
    ];
    return verification_send();
}

function verification_send(): array
{
    $fail = static fn(string $error): array => ['ok' => false, 'error' => $error];
    $pending = verification_pending();
    if (!$pending || !email_verification_enabled()) {
        return $fail('Please sign in again.');
    }
    if ($pending['purpose'] === 'profile'
        && (!auth_is_logged_in() || (int) $_SESSION['user_id'] !== (int) $pending['user_id'])) {
        return $fail('Please sign in again.');
    }
    $now = time();
    try {
        db()->beginTransaction();
        // Serializes issuance and verification per account on MySQL.
        $user = verification_user((int) $pending['user_id'], true);
        if (!$user || $user['account_status'] !== 'active'
            || !hash_equals($pending['credential_hash'], verification_credential($user))) {
            db()->rollBack();
            unset($_SESSION['_email_pending']);
            return $fail('Your account details changed. Please sign in again.');
        }
        $stmt = db()->prepare('SELECT * FROM email_verification WHERE user_id = ?');
        $stmt->execute([$user['user_id']]);
        $previous = $stmt->fetch() ?: [];
        if ((int) ($previous['blocked_until'] ?? 0) > $now) {
            db()->rollBack();
            return $fail('Too many incorrect codes. Try again in 15 minutes.');
        }
        if ($now - (int) ($previous['last_sent_at'] ?? 0) < VERIFICATION_RESEND_SECONDS) {
            db()->rollBack();
            return $fail('Please wait 60 seconds between code requests.');
        }
        $windowStart = (int) ($previous['send_window_start'] ?? $now);
        $sends = (int) ($previous['sends_in_window'] ?? 0);
        if ($now - $windowStart >= 3600) {
            $windowStart = $now;
            $sends = 0;
        }
        if ($sends >= VERIFICATION_MAX_SENDS) {
            db()->rollBack();
            return $fail('Too many code requests. Try again after one hour from your first request.');
        }
        // Resending does not reset the guessing limit.
        $guesses = (int) ($previous['attempts'] ?? 0);
        if ((int) ($previous['blocked_until'] ?? 0) > 0 && (int) $previous['blocked_until'] <= $now) {
            $guesses = 0;
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $challengeId = bin2hex(random_bytes(16));
        $values = [$challengeId, hash('sha256', $pending['nonce']), password_hash($code, PASSWORD_DEFAULT),
            $pending['credential_hash'], $now + VERIFICATION_CODE_SECONDS, $guesses,
            $now, $windowStart, $sends + 1, 0, 0, $user['user_id']];
        if ($previous) {
            $sql = 'UPDATE email_verification SET challenge_id = ?, binding_hash = ?, code_hash = ?,
                    credential_hash = ?, expires_at = ?, attempts = ?, last_sent_at = ?,
                    send_window_start = ?, sends_in_window = ?, blocked_until = ?, consumed = ?
                    WHERE user_id = ?';
        } else {
            $sql = 'INSERT INTO email_verification (challenge_id, binding_hash, code_hash,
                    credential_hash, expires_at, attempts, last_sent_at, send_window_start,
                    sends_in_window, blocked_until, consumed, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        }
        db()->prepare($sql)->execute($values);
        db()->commit();
        $_SESSION['_email_pending']['challenge_id'] = $challengeId;
        $sent = mail_verification_code($user['email'], $code);
        $_SESSION['_email_pending']['mail_sent'] = $sent;
        if (!$sent) {
            // An undelivered code must never authorize a session.
            db()->prepare('UPDATE email_verification SET consumed = 1 WHERE user_id = ? AND challenge_id = ?')
                ->execute([$user['user_id'], $challengeId]);
            return $fail('We could not send your code. Please retry after 60 seconds or contact the project team.');
        }
        return ['ok' => true, 'error' => null];
    } catch (Throwable $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        error_log('EduFlex email verification issuance failed.');
        return $fail('Verification is temporarily unavailable. Please try again.');
    }
}

function verification_finish(string $code, bool $trust): array
{
    $fail = static fn(string $error): array => ['ok' => false, 'error' => $error];
    $pending = verification_pending();
    if (!$pending || !email_verification_enabled()) {
        return $fail('Your verification session expired. Please sign in again.');
    }
    if ($pending['purpose'] === 'profile'
        && (!auth_is_logged_in() || (int) $_SESSION['user_id'] !== (int) $pending['user_id'])) {
        return $fail('Please sign in again.');
    }
    try {
        db()->beginTransaction();
        $user = verification_user((int) $pending['user_id'], true);
        $stmt = db()->prepare('SELECT * FROM email_verification WHERE user_id = ?');
        $stmt->execute([$pending['user_id']]);
        $challenge = $stmt->fetch();
        if (!$user || $user['account_status'] !== 'active'
            || !hash_equals($pending['credential_hash'], verification_credential($user))) {
            db()->rollBack();
            unset($_SESSION['_email_pending']);
            return $fail('Your account details changed. Please sign in again.');
        }
        if (!$challenge || ($challenge['challenge_id'] !== ($pending['challenge_id'] ?? ''))
            || !hash_equals($challenge['binding_hash'], hash('sha256', $pending['nonce']))) {
            db()->rollBack();
            return $fail('This code request was replaced. Request a new code in this browser.');
        }
        if ((int) $challenge['blocked_until'] > time() || (int) $challenge['attempts'] >= VERIFICATION_MAX_GUESSES) {
            db()->rollBack();
            return $fail('Too many incorrect codes. Try again in 15 minutes and request a new code.');
        }
        if ((int) $challenge['expires_at'] <= time() || (int) $challenge['consumed'] !== 0) {
            db()->rollBack();
            return $fail('This code has expired or is unavailable. Request a new code.');
        }
        if (!preg_match('/^\d{6}$/D', $code) || !password_verify($code, $challenge['code_hash'])) {
            $attempts = (int) $challenge['attempts'] + 1;
            $blocked = $attempts >= VERIFICATION_MAX_GUESSES ? time() + VERIFICATION_LOCK_SECONDS : 0;
            db()->prepare('UPDATE email_verification SET attempts = ?, blocked_until = ? WHERE user_id = ?')
                ->execute([$attempts, $blocked, $user['user_id']]);
            db()->commit();
            return $fail($blocked ? 'Too many incorrect codes. Try again in 15 minutes.' : 'That code is incorrect.');
        }
        // Consume inside the same transaction as email verification and browser trust.
        db()->prepare('UPDATE email_verification SET consumed = 1, attempts = 0 WHERE user_id = ?')->execute([$user['user_id']]);
        db()->prepare('UPDATE user SET email_verified_at = COALESCE(email_verified_at, ?) WHERE user_id = ?')
            ->execute([gmdate('Y-m-d H:i:s'), $user['user_id']]);
        $token = null;
        if ($pending['purpose'] === 'login' && $trust) {
            $token = trusted_browser_store($user);
        } elseif ($pending['purpose'] === 'login') {
            $previousToken = $_COOKIE[TRUSTED_BROWSER_COOKIE] ?? '';
            if (is_string($previousToken)) {
                db()->prepare('DELETE FROM trusted_browser WHERE token_hash = ? AND user_id = ?')
                    ->execute([hash('sha256', $previousToken), $user['user_id']]);
            }
        }
        db()->commit();
        $purpose = $pending['purpose'];
        $next = auth_safe_redirect_target($pending['next'] ?? null);
        unset($_SESSION['_email_pending']);
        if ($token !== null) {
            trusted_browser_cookie($token, time() + TRUSTED_BROWSER_SECONDS);
        } elseif ($purpose === 'login') {
            trusted_browser_cookie('', time() - 3600);
        }
        if ($purpose === 'login') {
            auth_complete_login($user);
        }
        return ['ok' => true, 'purpose' => $purpose, 'next' => $next, 'error' => null];
    } catch (Throwable $e) {
        if (db()->inTransaction()) { db()->rollBack(); }
        error_log('EduFlex email verification completion failed.');
        return $fail('Verification is temporarily unavailable. Please try again.');
    }
}

function trusted_browser_matches(array $user): bool
{
    return trusted_browser_expiry($user) > time();
}

function trusted_browser_expiry(array $user): int
{
    $token = $_COOKIE[TRUSTED_BROWSER_COOKIE] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
        return 0;
    }
    $stmt = db()->prepare('SELECT credential_hash, expires_at FROM trusted_browser WHERE token_hash = ? AND user_id = ? AND expires_at > ?');
    $stmt->execute([hash('sha256', $token), $user['user_id'], time()]);
    $row = $stmt->fetch();
    return $row && hash_equals($row['credential_hash'], verification_credential($user)) ? (int) $row['expires_at'] : 0;
}

/** Activate the registration grant only once, after a correct first password. */
function trusted_browser_activate_registration(array $user): void
{
    $token = $_COOKIE[TRUSTED_BROWSER_COOKIE] ?? '';
    if (!is_string($token) || !trusted_browser_matches($user)) { return; }
    $now = time();
    $stmt = db()->prepare('UPDATE trusted_browser SET registration_pending = 0, created_at = ?, expires_at = ?
        WHERE token_hash = ? AND user_id = ? AND credential_hash = ? AND registration_pending = 1 AND expires_at > ?');
    $stmt->execute([$now, $now + TRUSTED_BROWSER_SECONDS, hash('sha256', $token),
        $user['user_id'], verification_credential($user), $now]);
    if ($stmt->rowCount() === 1) {
        trusted_browser_cookie($token, $now + TRUSTED_BROWSER_SECONDS);
    }
}

function trusted_browser_cookie(string $value, int $expires): void
{
    if (!headers_sent()) {
        $path = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
        // All callers are root authentication pages; scope trust to this app.
        setcookie(TRUSTED_BROWSER_COOKIE, $value, [
            'expires' => $expires, 'path' => rtrim($path, '/') . '/',
            'httponly' => true, 'secure' => security_is_https(), 'samesite' => 'Lax',
        ]);
        // Reflect the cookie on this request too; never expose it in markup.
        if ($value === '') { unset($_COOKIE[TRUSTED_BROWSER_COOKIE]); }
        else { $_COOKIE[TRUSTED_BROWSER_COOKIE] = $value; }
    }
}

function trusted_browser_store(array $user, bool $registrationPending = false): string
{
    $token = bin2hex(random_bytes(32));
    db()->prepare('DELETE FROM trusted_browser WHERE user_id = ? AND expires_at <= ?')->execute([$user['user_id'], time()]);
    // Replace this browser's previous credential; other browsers stay trusted.
    $previous = $_COOKIE[TRUSTED_BROWSER_COOKIE] ?? '';
    if (is_string($previous)) {
        db()->prepare('DELETE FROM trusted_browser WHERE token_hash = ? AND user_id = ?')
            ->execute([hash('sha256', $previous), $user['user_id']]);
    }
    db()->prepare('INSERT INTO trusted_browser (user_id, token_hash, credential_hash, created_at, expires_at, registration_pending) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$user['user_id'], hash('sha256', $token), verification_credential($user), time(), time() + TRUSTED_BROWSER_SECONDS, $registrationPending ? 1 : 0]);
    return $token;
}

function verification_revoke(int $userId): void
{
    if (!email_verification_enabled()) { return; }
    db()->prepare('DELETE FROM trusted_browser WHERE user_id = ?')->execute([$userId]);
    db()->prepare('DELETE FROM email_verification WHERE user_id = ?')->execute([$userId]);
}
