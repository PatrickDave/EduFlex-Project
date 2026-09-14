<?php
/**
 * EduFlex — authentication test suite.
 *
 * Runs the real functions in includes/auth.php against a throwaway in-memory
 * database, so nothing here touches your development data.
 *
 *     php tests/auth_test.php
 *
 * Every check below corresponds to something a panel member could reasonably
 * ask you to demonstrate: that passwords are hashed, that duplicate emails are
 * rejected, that a wrong password does not reveal whether the account exists.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

/* ---------------------------------------------------------------- harness */

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  PASS  $label\n";
    } else {
        $failed++;
        echo "  FAIL  $label\n";
    }
}

function section(string $name): void
{
    echo "\n$name\n";
}

/* ------------------------------------------------------- throwaway schema */

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// SQLite equivalents of the three tables auth_register touches.
$pdo->exec("
CREATE TABLE user (
  user_id        INTEGER PRIMARY KEY AUTOINCREMENT,
  full_name      TEXT NOT NULL,
  email          TEXT NOT NULL UNIQUE,
  password_hash  TEXT NOT NULL,
  program        TEXT NOT NULL,
  year_level     INTEGER NOT NULL,
  account_status TEXT NOT NULL,
  created_at     TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE subscription (
  subscription_id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id         INTEGER NOT NULL UNIQUE,
  plan_type       TEXT NOT NULL,
  status          TEXT NOT NULL,
  started_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at      TEXT NULL
);
CREATE TABLE notification (
  notification_id   INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id           INTEGER NOT NULL,
  notification_type TEXT NOT NULL,
  title             TEXT NOT NULL,
  message           TEXT NOT NULL,
  is_read           INTEGER NOT NULL DEFAULT 0,
  created_at        TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
");

db_set_connection($pdo);

echo "EduFlex auth test suite\n=======================\n";

/* ---------------------------------------------------------- registration */

section('Registration validation');

$r = auth_register('', 'bad', 'short', false);
check('rejects an empty name',            isset($r['errors']['full_name']));
check('rejects a malformed email',        isset($r['errors']['email']));
check('rejects a password under 8 chars', isset($r['errors']['password']));
check('rejects unticked terms',           isset($r['errors']['agree']));
check('returns ok=false',                 $r['ok'] === false);

section('Successful registration');

$r = auth_register('Patrick Dave Cagas', 'Patrick@Example.COM ', 'correct-horse-8', true);
check('creates the account', $r['ok'] === true);
check('returns a user id',   is_int($r['user_id']) && $r['user_id'] > 0);

$row = $pdo->query('SELECT * FROM user WHERE user_id = 1')->fetch();
check('stores the full name',        $row['full_name'] === 'Patrick Dave Cagas');
check('lowercases and trims email',  $row['email'] === 'patrick@example.com');
check('sets the account active',     $row['account_status'] === 'active');

section('Password storage');

check('does NOT store the plain password',
    $row['password_hash'] !== 'correct-horse-8');
check('stores a bcrypt hash',
    str_starts_with($row['password_hash'], '$2y$'));
check('hash verifies against the original password',
    password_verify('correct-horse-8', $row['password_hash']));
check('hash rejects a wrong password',
    !password_verify('wrong-password', $row['password_hash']));

section('Related rows created in the same transaction');

$sub = $pdo->query('SELECT * FROM subscription WHERE user_id = 1')->fetch();
check('creates a subscription row', $sub !== false);
check('defaults the plan to free',  $sub && $sub['plan_type'] === 'free');

$notif = $pdo->query('SELECT COUNT(*) c FROM notification WHERE user_id = 1')->fetch();
check('creates a welcome notification', (int) $notif['c'] === 1);

section('Duplicate accounts');

$r = auth_register('Someone Else', 'patrick@example.com', 'another-pass-9', true);
check('rejects a duplicate email',   $r['ok'] === false && isset($r['errors']['email']));

$count = $pdo->query('SELECT COUNT(*) c FROM user')->fetch();
check('does not create a second row', (int) $count['c'] === 1);

/* ---------------------------------------------------------------- login */

section('Login');

$r = auth_login('patrick@example.com', 'wrong-password');
check('rejects a wrong password', $r['ok'] === false);
$wrongPasswordMessage = $r['errors']['form'] ?? '';

$r = auth_login('nobody@example.com', 'whatever-1234');
check('rejects an unknown email', $r['ok'] === false);
$unknownEmailMessage = $r['errors']['form'] ?? '';

check('gives the SAME message for wrong password and unknown email',
    $wrongPasswordMessage !== '' && $wrongPasswordMessage === $unknownEmailMessage);

$r = auth_login('  PATRICK@example.com ', 'correct-horse-8');
check('accepts correct credentials, case and space insensitive', $r['ok'] === true);
check('populates the session', ($_SESSION['user_id'] ?? null) === 1);

section('Session state');

check('reports the user as logged in', auth_is_logged_in() === true);
$u = auth_user();
check('auth_user returns the row',      is_array($u) && $u['email'] === 'patrick@example.com');
check('auth_user hides the password',   is_array($u) && !array_key_exists('password_hash', $u));
check('first name is extracted',        auth_first_name() === 'Patrick');

section('Suspended accounts');

$pdo->exec("UPDATE user SET account_status = 'suspended' WHERE user_id = 1");
$r = auth_login('patrick@example.com', 'correct-horse-8');
check('blocks a suspended account', $r['ok'] === false);
$pdo->exec("UPDATE user SET account_status = 'active' WHERE user_id = 1");

section('Logout');

auth_logout();
check('clears the session', auth_is_logged_in() === false);

/* ------------------------------------------------------- mastery helpers */

section('Mastery bands (must match assets/js/eduflex.js)');

check('92% with 18 items is mastered',    mastery_band(92.0, 18) === 'mastered');
check('85% exactly is mastered',          mastery_band(85.0, 10) === 'mastered');
check('84.9% is developing',              mastery_band(84.9, 10) === 'developing');
check('70% exactly is developing',        mastery_band(70.0, 10) === 'developing');
check('69.9% is weak',                    mastery_band(69.9, 10) === 'weak');
check('4 items is always "none"',         mastery_band(95.0, 4)  === 'none');
check('5 items is the minimum that counts', mastery_band(95.0, 5) === 'mastered');
check('null score is "none"',             mastery_band(null, 20) === 'none');

/* ---------------------------------------------------------------- escaping */

section('Output escaping');

check('escapes angle brackets', e('<script>') === '&lt;script&gt;');
check('escapes quotes',         e('"x"') === '&quot;x&quot;');

/* ------------------------------------------------------------------ done */

echo "\n=======================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
