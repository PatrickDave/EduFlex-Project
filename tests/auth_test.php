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
$GLOBALS['eduflex_test_mail_config'] = ['enabled' => false];

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

$r = auth_register('Patrick Dave Cagas', 'Patrick@Example.COM ', 'correct-horse-8', true, 'correct-horse-8');
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

$r = auth_register('Someone Else', 'patrick@example.com', 'another-pass-9', true, 'another-pass-9');
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

/* ------------------------------------------------- show / hide password */

section('The password reveal control');

/* Added 12 October 2026. The behaviour lives in eduflex.js and is driven in a
   real browser by tests/password_browser_test.py. What is checked here is the
   markup contract that behaviour depends on, because these are the parts that
   fail silently or dangerously rather than visibly. */

$toggle = password_toggle_html('password');

check('it is a button, not a link or a div',
    str_starts_with($toggle, '<button '));

/* The one that really matters. A <button> inside a <form> submits it by
   default, so a missing type="button" would mean that revealing your password
   posts the login form. It would look like a broken login, not like a missing
   attribute, and you would hunt for it in the wrong place. */
check('it is type="button", so it cannot submit the form it sits in',
    str_contains($toggle, 'type="button"'));

check('it starts in the hidden state',
    str_contains($toggle, 'aria-pressed="false"')
    && str_contains($toggle, 'aria-label="Show password"'));

check('it says which field it controls',
    str_contains($toggle, 'aria-controls="password"'));

check('it ships both eyes, so CSS can swap them without JavaScript',
    str_contains($toggle, 'ef-eye-open') && str_contains($toggle, 'ef-eye-shut'));

check('the drawings are hidden from screen readers, the button carries the name',
    substr_count($toggle, 'aria-hidden="true"') === 2);

check('it is a cat eye: an almond with a vertical slit, not a round pupil',
    // ry greater than rx is the slit. A round pupil would be rx === ry.
    str_contains($toggle, 'rx="1.9"') && str_contains($toggle, 'ry="4.2"'));

check('the icon is inline SVG, not a font or a sprite from a CDN',
    // Rule 3 and the CSP. An <i class="fa-..."> or an external <use> would be
    // invisible in the defense room and blocked by the policy.
    str_contains($toggle, '<svg')
    && !str_contains($toggle, '<use')
    && !str_contains($toggle, 'http'));

check('the field id is escaped, so it cannot break out of the attribute',
    (function () {
        $out = password_toggle_html('x" onfocus="alert(1)');
        return !str_contains($out, 'onfocus="alert(1)"');
    })());

section('Both public forms use it');

$loginSrc    = (string) file_get_contents(__DIR__ . '/../login.php');
$registerSrc = (string) file_get_contents(__DIR__ . '/../register.php');

check('login has one toggle',
    substr_count($loginSrc, 'password_toggle_html(') === 1);
check('registration has two, one per field',
    substr_count($registerSrc, 'password_toggle_html(') === 2);

check('every password field on those two pages is wrapped so the button has a home',
    (function () use ($loginSrc, $registerSrc) {
        // A new password input added without its .ef-password wrapper would put
        // the button at the top-left of the page rather than inside the field.
        foreach ([$loginSrc, $registerSrc] as $src) {
            $fields  = substr_count($src, 'type="password"');
            $wrapped = substr_count($src, 'class="ef-password"');
            if ($fields !== $wrapped) { return false; }
        }
        return true;
    })());

check('the behaviour is wired up at load', (function () {
    $js = (string) file_get_contents(__DIR__ . '/../assets/js/eduflex.js');
    return str_contains($js, 'function initPasswordToggles()')
        && str_contains($js, 'initPasswordToggles();');
})());

check('the caret is preserved across the type swap', (function () {
    // Changing input.type sends the caret to the end in most engines, which is
    // maddening halfway through a long password.
    $js = (string) file_get_contents(__DIR__ . '/../assets/js/eduflex.js');
    return str_contains($js, 'setSelectionRange');
})());

check('the stylesheet defines every class the markup uses', (function () {
    $css = (string) file_get_contents(__DIR__ . '/../assets/css/eduflex.css');
    foreach (['.ef-password', '.ef-password-toggle', '.ef-eye-open',
              '.ef-eye-shut', '.ef-eye-pupil'] as $sel) {
        if (!str_contains($css, $sel)) { return false; }
    }
    return true;
})());

check('the input is padded so a long password cannot run under the button',
    (function () {
        $css = (string) file_get_contents(__DIR__ . '/../assets/css/eduflex.css');
        return (bool) preg_match('/\.ef-password\s+\.ef-input\s*\{[^}]*padding-right:\s*4[0-9]px/s', $css);
    })());

/* --------------------------------------------------------- cache busting */

section('Our own CSS and JS carry a version');

/* 12 October 2026. The password reveal shipped working, tested and verified in
   a browser, and on Patrick's screen the icon sat in the wrong place and did
   nothing when clicked. The code was fine. The markup comes from PHP so it
   appeared at once, but eduflex.css and eduflex.js came from his cache: the old
   stylesheet had no rule to position the button and the old script had no
   handler to run.

   Apache sends no Cache-Control for these files, so a browser may reuse them
   without asking, and does. This is the worst kind of bug to report, because it
   looks broken to the person seeing it and fine to the person who wrote it. */

check('asset() appends a version token', (function () {
    return (bool) preg_match('/^assets\/css\/eduflex\.css\?v=[0-9]+$/',
        asset('assets/css/eduflex.css'));
})());

check('the token is the file\'s own modification time', (function () {
    $expected = (string) filemtime(__DIR__ . '/../assets/css/eduflex.css');
    return str_ends_with(asset('assets/css/eduflex.css'), '?v=' . $expected);
})());

check('it resolves the file through a "../" prefix, as the app pages write it',
    (function () {
        // app/*.php reach up one level. The hops are stripped only to find the
        // file; the href itself must keep them or the link breaks.
        $out = asset('../assets/js/eduflex.js');
        return str_starts_with($out, '../assets/js/eduflex.js?v=')
            && !str_ends_with($out, '?v=0');
    })());

check('a missing file degrades instead of throwing',
    asset('assets/css/does-not-exist.css') === 'assets/css/does-not-exist.css?v=0');

check('the href is escaped',
    !str_contains(asset('assets/x".css'), 'x".css'));

check('no page still links our CSS or JS without a version', (function () {
    // The whole point. One un-versioned reference is one page that can serve a
    // stale stylesheet after the next change.
    $files = array_merge(
        glob(__DIR__ . '/../*.php') ?: [],
        glob(__DIR__ . '/../app/*.php') ?: []
    );
    foreach ($files as $file) {
        $src = (string) file_get_contents($file);
        // A raw href/src to our own asset with no query string.
        if (preg_match('#(?:href|src)="(?:\.\./)?assets/(?:css|js)/eduflex\.(?:css|js)"#', $src)) {
            return false;
        }
    }
    return true;
})());

check('build_pages.py generates versioned links too', (function () {
    // The eleven app pages come from the SHELL. Fixing the generated files
    // without fixing the template means the next build undoes it.
    $build = (string) file_get_contents(__DIR__ . '/../build_pages.py');
    return str_contains($build, "asset('../assets/css/eduflex.css')")
        && str_contains($build, "asset('../assets/js/eduflex.js')");
})());

/* ------------------------------------------------------------------ done */

echo "\n=======================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
