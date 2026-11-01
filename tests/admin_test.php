<?php
/**
 * EduFlex — the support queue, and the gate in front of it.
 *
 *     php tests/admin_test.php
 *
 * No database server, no network, no API key. Builds its own SQLite schema.
 *
 * This is the one part of EduFlex that deliberately reads across learners.
 * Everywhere else ownership is enforced in SQL by filtering on user_id; here
 * the whole point is to see other people's requests, so the only thing standing
 * between a learner and the queue is admin_is_admin(). Most of this file is
 * about that one function and the door it holds.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "  PASS  $label\n"; }
    else            { $failed++; echo "  FAIL  $label\n"; }
}
function section(string $name): void { echo "\n$name\n"; }

echo "EduFlex support queue suite\n===========================\n";

/* ----------------------------------------------------------- test database */

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("
CREATE TABLE user (
  user_id INTEGER PRIMARY KEY AUTOINCREMENT,
  full_name TEXT NOT NULL, email TEXT NOT NULL,
  password_hash TEXT NOT NULL DEFAULT 'x',
  account_status TEXT NOT NULL DEFAULT 'active',
  is_admin INTEGER NOT NULL DEFAULT 0);
CREATE TABLE support_request (
  request_id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL, request_type TEXT NOT NULL,
  subject TEXT NOT NULL, message TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'open',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE notification (
  notification_id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL, notification_type TEXT NOT NULL,
  title TEXT NOT NULL, message TEXT NOT NULL,
  is_read INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
");
db_set_connection($pdo);

$pdo->exec("INSERT INTO user (full_name, email, is_admin) VALUES
  ('Patrick Cagas', 'patrick@example.com', 1),
  ('A Learner',     'learner@example.com', 0),
  ('Another',       'other@example.com',   0)");

$pdo->exec("INSERT INTO support_request (user_id, request_type, subject, message, status) VALUES
  (2, 'bug',        'Upload fails',   'The PDF will not upload at all.',        'open'),
  (2, 'ai_quality', 'Wrong answer',   'A generated question marked me wrong.',  'open'),
  (3, 'suggestion', 'Dark mode',      'Please consider a dark theme someday.',  'resolved'),
  (3, 'question',   'How do topics?', 'How does topic detection decide things?','in_progress')");

/* ------------------------------------------------------------- the gate */

section('Who is an admin');

check('the flag opens the door',
    admin_is_admin(['user_id' => 1, 'is_admin' => 1]));

check('a learner is not an admin',
    !admin_is_admin(['user_id' => 2, 'is_admin' => 0]));

check('a missing flag is not an admin',
    // Every row written before this column existed. Default 0 in the schema,
    // but a partial SELECT can hand this function a row without the key.
    !admin_is_admin(['user_id' => 2]));

check('null is not an admin', !admin_is_admin(null));

check('MySQL\'s string "1" counts, because PDO hands back strings',
    admin_is_admin(['is_admin' => '1']));

check('"0" does not', !admin_is_admin(['is_admin' => '0']));

check('an empty string does not', !admin_is_admin(['is_admin' => '']));

check('a wrong number in phpMyAdmin does not make somebody an admin', (function () {
    // The flag is set by hand, so a typo is entirely possible. Every value
    // here is truthy in PHP and every one is refused, because the check is
    // "exactly 1" rather than "not empty".
    //
    // Deliberately NOT asserted: '01', ' 1' and boolean true all cast to 1 and
    // would be accepted. None can reach this function from the database.
    // is_admin is TINYINT(1), so MySQL stores '01' as 1 and PDO returns "1" or
    // 1, never a boolean and never a padded string. Testing against inputs the
    // column cannot produce would be inventing a threat and then guarding it.
    foreach (['2', 2, 'yes', 'true', -1, 0, '0', ''] as $odd) {
        if (admin_is_admin(['is_admin' => $odd])) { return false; }
    }
    return true;
})());

check('both shapes a TINYINT really comes back as are accepted',
    // PDO returns "1" from MySQL and 1 from SQLite. Both must open the door,
    // or the suite passes here and the live page turns the team away.
    admin_is_admin(['is_admin' => '1']) && admin_is_admin(['is_admin' => 1]));

/* --------------------------------------------------------- reading the queue */

section('Reading the queue');

check('an admin sees every learner\'s requests', count(admin_requests()) === 4);

check('newest first', (function () {
    $rows = admin_requests();
    return (int) $rows[0]['request_id'] === 4;
})());

check('each row carries who sent it', (function () {
    $rows = admin_requests();
    return isset($rows[0]['full_name'], $rows[0]['email'])
        && $rows[0]['full_name'] !== '';
})());

check('filtering by status narrows it',
    count(admin_requests('open')) === 2);

check('filtering by type narrows it',
    count(admin_requests('', 'ai_quality')) === 1);

check('both filters together', (function () {
    return count(admin_requests('open', 'bug')) === 1
        && count(admin_requests('resolved', 'bug')) === 0;
})());

check('an unknown status is ignored rather than returning nothing', (function () {
    // A crafted query string must not be able to blank the screen or reach the
    // SQL. An unrecognised filter falls back to "no filter".
    return count(admin_requests('; DROP TABLE user--')) === 4
        && count(admin_requests('', "' OR 1=1--")) === 4;
})());

check('the user table is still there afterwards',
    (int) $pdo->query('SELECT COUNT(*) FROM user')->fetchColumn() === 3);

check('one request can be fetched by id', (function () {
    $r = admin_request(1);
    return $r !== null && (string) $r['subject'] === 'Upload fails';
})());

check('a request that does not exist returns null',
    admin_request(99999) === null);

section('The counts');

check('they total every request', (function () {
    $c = admin_status_counts();
    return $c['total'] === 4;
})());

check('each status is counted', (function () {
    $c = admin_status_counts();
    return $c['open'] === 2 && $c['resolved'] === 1 && $c['in_progress'] === 1;
})());

check('a status with nothing in it is still present as zero', (function () {
    // So the screen can show "0 closed" rather than silently dropping a column.
    $c = admin_status_counts();
    return array_key_exists('closed', $c) && $c['closed'] === 0;
})());

/* ------------------------------------------------------------ moving one on */

section('Changing a status');

check('an admin can move a request along', (function () {
    $r = admin_set_status(1, 'in_progress');
    return $r['ok'] === true && $r['changed'] === true;
})());

check('and it really changed in the database', (function () {
    $r = admin_request(1);
    return (string) $r['status'] === 'in_progress';
})());

check('the learner who sent it is told', (function () use ($pdo) {
    // Rule 8: the notification is written by the function that performs the
    // event, never by a page render.
    $n = $pdo->query("SELECT user_id, notification_type, title FROM notification
                      ORDER BY notification_id DESC LIMIT 1")->fetch();
    return $n !== false && (int) $n['user_id'] === 2
        && (string) $n['notification_type'] === 'support';
})());

check('"support" is a real notification type, not silently stored as system',
    array_key_exists('support', NOTIFY_TYPES));

check('nobody else is notified', (function () use ($pdo) {
    return (int) $pdo->query('SELECT COUNT(*) FROM notification')->fetchColumn() === 1;
})());

check('setting the same status again reports no change', (function () {
    $r = admin_set_status(1, 'in_progress');
    return $r['ok'] === true && $r['changed'] === false;
})());

check('and does not notify a second time', (function () use ($pdo) {
    return (int) $pdo->query('SELECT COUNT(*) FROM notification')->fetchColumn() === 1;
})());

check('a status outside SUPPORT_STATUSES is refused', (function () {
    $r = admin_set_status(2, 'deleted');
    return $r['ok'] === false && $r['changed'] === false;
})());

check('and the request is untouched', (function () {
    return (string) admin_request(2)['status'] === 'open';
})());

check('a status cannot be smuggled in as SQL', (function () {
    $r = admin_set_status(2, "open'; UPDATE support_request SET status='resolved'--");
    return $r['ok'] === false;
})());

check('every request still holds a known status', (function () {
    foreach (admin_requests() as $row) {
        if (!in_array((string) $row['status'], SUPPORT_STATUSES, true)) { return false; }
    }
    return true;
})());

check('changing a request that does not exist fails cleanly', (function () {
    $r = admin_set_status(99999, 'resolved');
    return $r['ok'] === false && is_string($r['error']);
})());

section('What the screen offers');

check('the actions are a subset of the real statuses', (function () {
    foreach (array_keys(ADMIN_STATUS_ACTIONS) as $status) {
        if (!in_array($status, SUPPORT_STATUSES, true)) { return false; }
    }
    return true;
})());

check('every action has a label a person can read', (function () {
    foreach (ADMIN_STATUS_ACTIONS as $verb) {
        if (trim($verb) === '') { return false; }
    }
    return true;
})());

/* ------------------------------------------------- the door, in the pages */

section('Feedback is its own view');

check('feedback is the AI-quality reports and the suggestions',
    ADMIN_FEEDBACK_TYPES === ['ai_quality', 'suggestion']);

check('every feedback type is a real request type', (function () {
    foreach (ADMIN_FEEDBACK_TYPES as $t) {
        if (!array_key_exists($t, SUPPORT_TYPES)) { return false; }
    }
    return true;
})());

check('admin_feedback() returns only those types', (function () {
    foreach (admin_feedback() as $row) {
        if (!in_array((string) $row['request_type'], ADMIN_FEEDBACK_TYPES, true)) {
            return false;
        }
    }
    return true;
})());

check('it finds both kinds, not just the first', (function () {
    // Each type is queried separately and merged. A bug in the merge shows up
    // as one kind present and the other missing.
    $kinds = array_unique(array_column(admin_feedback(), 'request_type'));
    sort($kinds);
    return $kinds === ['ai_quality', 'suggestion'];
})());

check('the merged list is still newest first', (function () {
    // Each type comes back sorted on its own; without a re-sort the screen
    // shows all of one kind and then all of the other.
    $ids = array_map('intval', array_column(admin_feedback(), 'request_id'));
    $sorted = $ids;
    rsort($sorted);
    return $ids === $sorted;
})());

check('it can be narrowed by status', (function () {
    foreach (admin_feedback('resolved') as $row) {
        if ((string) $row['status'] !== 'resolved') { return false; }
    }
    return true;
})());

check('a plain help request is not treated as feedback', (function () {
    foreach (admin_feedback() as $row) {
        if ((string) $row['request_type'] === 'bug') { return false; }
    }
    return true;
})());

section('The dashboard numbers');

check('waiting counts open and in progress, not resolved', (function () {
    $c = admin_status_counts();
    return admin_open_count() === (int) $c['open'] + (int) $c['in_progress'];
})());

check('type counts cover every known type', (function () {
    $c = admin_type_counts();
    foreach (array_keys(SUPPORT_TYPES) as $t) {
        if (!array_key_exists($t, $c)) { return false; }
    }
    return true;
})());

check('a type nobody has used reads as zero, not missing', (function () {
    $c = admin_type_counts();
    return array_key_exists('account', $c) && $c['account'] === 0;
})());

check('the type counts add up to the total', (function () {
    return array_sum(admin_type_counts()) === admin_status_counts()['total'];
})());

section('Both doors are locked, not just the visible one');

$page   = (string) file_get_contents(__DIR__ . '/../app/admin.php');
$action = (string) file_get_contents(__DIR__ . '/../app/actions/admin_status.php');

check('every admin page calls admin_require()', (function () {
    foreach (['admin.php', 'admin_requests.php', 'admin_feedback.php'] as $f) {
        $src = (string) file_get_contents(__DIR__ . '/../app/' . $f);
        if (!str_contains($src, 'admin_require()')) { return false; }
    }
    return true;
})());

check('and each does so before any output', (function () {
    // A gate after the HTML has started is not a gate: headers are already sent
    // and redirect() cannot fire.
    foreach (['admin.php', 'admin_requests.php', 'admin_feedback.php'] as $f) {
        $src  = (string) file_get_contents(__DIR__ . '/../app/' . $f);
        $gate = strpos($src, 'admin_require()');
        $html = strpos($src, '<!DOCTYPE html>');
        if ($gate === false || $html === false || $gate > $html) { return false; }
    }
    return true;
})());

check('the return page is chosen from a fixed list, not taken from the POST',
    (function () use ($action) {
        // An unchecked "back" field is an open redirect wearing a different
        // hat, and that exact mistake has already been made once here.
        return str_contains($action, "in_array($page, $pages, true)")
            || (bool) preg_match('/\$pages\s*=\s*\[[^\]]*admin_requests\.php/', $action);
    })());

check('the ACTION endpoint calls admin_require() too', (function () use ($action) {
    // The one that matters. A gate on the screen is not a gate on the system:
    // without this, anyone signed in could POST and change another learner's
    // request, link or no link.
    return str_contains($action, 'admin_require()');
})());

check('the action refuses anything but POST',
    str_contains($action, "REQUEST_METHOD'] !== 'POST'"));

check('the action checks CSRF', str_contains($action, 'csrf_check('));

check('the page is generated, not hand-written',
    str_contains($page, 'GENERATED FILE'));

check('build_pages.py is the source for it', (function () {
    $build = (string) file_get_contents(__DIR__ . '/../build_pages.py');
    return str_contains($build, '"admin.php"') && str_contains($build, 'admin_require();');
})());

check('the sidebar link is hidden from learners but never relied on', (function () {
    // Hiding the row is a courtesy. The protection is admin_require() in both
    // files above, which is what the previous four checks are about.
    $sidebar = (string) file_get_contents(__DIR__ . '/../partials/sidebar.php');
    return str_contains($sidebar, 'admin_is_admin($user');
})());

check('the predicate lives where every page can see it', (function () {
    // It was in includes/admin.php first, which only app/admin.php requires, so
    // on the other eleven pages the function did not exist, the guarded call
    // skipped, and the Support Queue link appeared nowhere at all. auth.php is
    // loaded by every page.
    $auth  = php_strip_whitespace(__DIR__ . '/../includes/auth.php');
    $admin = php_strip_whitespace(__DIR__ . '/../includes/admin.php');
    return str_contains($auth, 'function admin_is_admin')
        && !str_contains($admin, 'function admin_is_admin');
})());

check('auth_user() actually selects is_admin', (function () {
    // The columns are named rather than SELECT *, so a new one stays invisible
    // to every caller until it is added to that list. Without it
    // admin_is_admin() read a key that was never set, and the team was turned
    // away from their own queue with the flag correctly set in the database.
    $auth = php_strip_whitespace(__DIR__ . '/../includes/auth.php');
    return (bool) preg_match('/SELECT[^;]*\bis_admin\b[^;]*FROM user/s', $auth);
})());

check('the nav icon is inline SVG, per rule 15', (function () {
    $sidebar = (string) file_get_contents(__DIR__ . '/../partials/sidebar.php');
    return str_contains($sidebar, "'admin'     =>");
})());

section('The team has a door of its own');

/* 1 November 2026. Patrick asked three times for a separate admin sign-in, so
   there is one. What is NOT separate is the machinery behind it: auth_login()
   is still the only place a password is checked, so there is one password rule,
   one login throttle and one email-verification path. A second copy of any of
   those would be a second thing to keep correct. */

$adminLogin = (string) file_get_contents(__DIR__ . '/../admin_login.php');
$learnerLogin = (string) file_get_contents(__DIR__ . '/../login.php');

check('admin_login.php exists', is_file(__DIR__ . '/../admin_login.php'));

check('it authenticates through auth_login(), not a second copy',
    str_contains($adminLogin, 'auth_login('));

check('it does not hash or compare a password itself', (function () use ($adminLogin) {
    // A second password check is a second place to get the rules wrong, and
    // CLAUDE.md rule 9 says there is one.
    $code = $adminLogin;
    return !str_contains($code, 'password_verify')
        && !str_contains($code, 'password_hash(');
})());

check('it admits only accounts with is_admin',
    str_contains($adminLogin, 'admin_is_admin(auth_user())'));

check('a learner who gets the password right is signed back out', (function () use ($adminLogin) {
    // Correct credentials on a learner account must not leave them signed in
    // as a learner on a page that said "team sign-in".
    $at = strpos($adminLogin, 'admin_is_admin(auth_user())');
    return $at !== false && str_contains(substr($adminLogin, $at, 600), 'auth_logout()');
})());

check('it gives the same message whatever went wrong', (function () use ($adminLogin) {
    // Otherwise the page tells an attacker which addresses are admins.
    return substr_count($adminLogin, 'do not match a team account') === 2;
})());

check('it checks CSRF', str_contains($adminLogin, 'csrf_check('));

check('the learner door turns team accounts away',
    str_contains($learnerLogin, "redirect('admin_login.php?team=1')"));

check('it signals with a query flag, not a flash', (function () use ($learnerLogin, $adminLogin) {
    // auth_logout() destroys the session on the way across: a flash set before
    // it goes with the session, and one set after it has no session to live in.
    // The message was written and silently lost until this was changed. The
    // same problem was already solved this way for account deletion.
    return str_contains($learnerLogin, 'admin_login.php?team=1')
        && str_contains($adminLogin, "isset(\$_GET['team'])");
})());

check('and ends their session before doing so', (function () use ($learnerLogin) {
    $at = strpos($learnerLogin, 'admin_is_admin(auth_user())');
    return $at !== false && str_contains(substr($learnerLogin, $at, 500), 'auth_logout()');
})());

check('the two doors point at each other', (function () use ($adminLogin, $learnerLogin) {
    return str_contains($adminLogin, 'login.php')
        && str_contains($learnerLogin, 'admin_login.php');
})());

check('the team sign-in carries the password reveal, like the learner one',
    str_contains($adminLogin, 'password_toggle_html('));

check('its assets are versioned, per rule 18', (function () use ($adminLogin) {
    return str_contains($adminLogin, "asset('assets/css/eduflex.css')")
        && str_contains($adminLogin, "asset('assets/js/eduflex.js')");
})());

section('Two doors, one switch');

/* 2 November 2026. Patrick wanted the User/Admin tabs from the reference he
   sent, and separate pages behind them. The tabs are plain links, so the
   control works with no JavaScript and the address bar always says which door
   you are standing at. */

$tabs = (string) file_get_contents(__DIR__ . '/../partials/auth_tabs.php');

check('both sign-in pages render the same switch', (function () use ($adminLogin, $learnerLogin) {
    return str_contains($learnerLogin, "partials/auth_tabs.php")
        && str_contains($adminLogin, "partials/auth_tabs.php");
})());

check('the learner page marks User as current',
    str_contains($learnerLogin, "\$current = 'user'"));

check('the team page marks Admin as current',
    str_contains($adminLogin, "\$current = 'admin'"));

check('each tab is a link to the other page, not a script', (function () use ($tabs) {
    return str_contains($tabs, "'href' => 'login.php'")
        && str_contains($tabs, "'href' => 'admin_login.php'")
        && !str_contains($tabs, '<script')
        && !str_contains($tabs, 'onclick');
})());

check('the current tab is announced, not just coloured',
    str_contains($tabs, 'aria-selected') && str_contains($tabs, 'aria-current'));

check('an unexpected value falls back to User rather than showing neither', (function () use ($tabs) {
    return str_contains($tabs, "=== 'admin' ? 'admin' : 'user'");
})());

section('A learner never sees the help desk');

$sidebar = (string) file_get_contents(__DIR__ . '/../partials/sidebar.php');

check('no help desk row is added to the learner nav', (function () use ($sidebar) {
    // There was one, for admins browsing as learners. Patrick removed the
    // crossover entirely: the team account is a shared address with no
    // learning of its own, so it has no reason to be in the learner area.
    $code = php_strip_whitespace(__DIR__ . '/../partials/sidebar.php');
    return !str_contains($code, "'label' => 'Help Desk'");
})());

check('and no learner view row in the help desk nav', (function () {
    $code = php_strip_whitespace(__DIR__ . '/../partials/sidebar.php');
    return !str_contains($code, "'label' => 'Learner View'");
})());

check('the admin nav replaces the learner nav rather than extending it',
    str_contains($sidebar, 'if ($adminArea) {') && str_contains($sidebar, '$nav = ['));

section('The team account stays out of the learner area');

/* 2 November 2026. eduflex54@gmail.com is a shared address, not a participant:
   it has uploaded nothing, answered nothing and has no mastery, so every
   learner screen would show it an empty state that means nothing. Patrick asked
   that it be kept out entirely.
   
   The gate is in build_pages.py's SHELL, so it covers every generated page at
   once and a new page cannot be added without it. */

$build = (string) file_get_contents(__DIR__ . '/../build_pages.py');

check('the SHELL turns an admin away from learner pages',
    str_contains($build, "!str_starts_with(\$active, 'admin')")
    && str_contains($build, "redirect('admin.php')"));

check('it runs before any output', (function () {
    // A redirect after the HTML has started cannot fire.
    $page = (string) file_get_contents(__DIR__ . '/../app/dashboard.php');
    $gate = strpos($page, "redirect('admin.php')");
    $html = strpos($page, '<!DOCTYPE html>');
    return $gate !== false && $html !== false && $gate < $html;
})());

check('every generated learner page carries it', (function () {
    // Thirteen of them. Being in the SHELL is what makes that true without
    // anybody remembering to add it.
    foreach (['dashboard', 'companion', 'practice', 'growth', 'scores',
              'history', 'notifications', 'help', 'support', 'materials',
              'practice_run'] as $slug) {
        $src = (string) file_get_contents(__DIR__ . '/../app/' . $slug . '.php');
        if (!str_contains($src, "redirect('admin.php')")) { return false; }
    }
    return true;
})());

check('Settings is the one exception, and deliberately so', (function () use ($build) {
    // It is where the team changes its own password. A role with no way to do
    // that is worse than one that can see an empty dashboard.
    return str_contains($build, "\$active !== 'settings'");
})());

check('the admin nav offers Settings, so the exception is reachable', (function () {
    $sidebar = (string) file_get_contents(__DIR__ . '/../partials/sidebar.php');
    $at = strpos($sidebar, 'if ($adminArea) {');
    return $at !== false && str_contains(substr($sidebar, $at, 700), "'href' => 'settings.php'");
})());

check('the guard reads the flag, not a hard-coded address', (function () use ($build) {
    // The team address can change; the flag is the rule. Checked against the
    // CONDITION rather than the whole file, because the SHELL's comment names
    // the current address and scanning for it reports the opposite of the
    // truth. Comments explaining a decision are not the decision.
    return str_contains($build, 'admin_is_admin($user)')
        && !preg_match('/\$user\[.email.\]\s*===/', $build);
})());

check('an admin page does not turn itself away', (function () {
    // admin.php has $active = 'admin', so str_starts_with() is true and the
    // guard does not fire. If that ever inverted, the help desk would redirect
    // to itself forever.
    $page = (string) file_get_contents(__DIR__ . '/../app/admin.php');
    return str_contains($page, "\$active = 'admin'");
})());

section('Browse Help and FAQ');

$help = (string) file_get_contents(__DIR__ . '/../app/help.php');
$support2 = (string) file_get_contents(__DIR__ . '/../app/support.php');

check('there is a Help and FAQ page', is_file(__DIR__ . '/../app/help.php'));

check('it is generated, not hand-written',
    str_contains($help, 'GENERATED FILE'));

check('it is in the learner nav',
    str_contains($sidebar, "'href' => 'help.php'"));

check('it answers the questions that were on Support', (function () use ($help) {
    foreach (['cannot read my PDF', 'No data', 'affect my real grades',
              'delete it'] as $q) {
        if (!str_contains($help, $q)) { return false; }
    }
    return true;
})());

check('and some that were not', (function () use ($help) {
    // Mastery arithmetic and the grounding rule are the two things a panel
    // member is most likely to ask a learner about.
    return str_contains($help, 'mastery score worked out')
        && str_contains($help, 'cannot answer');
})());

check('the answers are written out, not generated', (function () use ($help) {
    // Nothing on this page may cost a provider call. It must read the same
    // every time, including in a room with no wifi.
    return !str_contains($help, 'ai_provider')
        && !str_contains($help, 'chat_ask')
        && !str_contains($help, 'questions_generate');
})());

check('it quotes the real mastery constants rather than hard-coded numbers',
    str_contains($help, 'MASTERY_MIN_ITEMS')
    && str_contains($help, 'MASTERY_MASTERED_AT'));

check('it opens without JavaScript', (function () use ($help) {
    // <details> rather than an accordion script: keyboard-operable, and it
    // still works if eduflex.js fails to load.
    return str_contains($help, '<details') && str_contains($help, '<summary>');
})());

check('Support points here instead of repeating the answers', (function () use ($support2) {
    return str_contains($support2, 'help.php')
        && !str_contains($support2, 'Common questions');
})());

check('so there is exactly one copy of the FAQ', (function () use ($help, $support2) {
    return substr_count($help, 'Common questions') === 1
        && substr_count($support2, 'Common questions') === 0;
})());

section('Granting team access');

$tool = (string) file_get_contents(__DIR__ . '/../tools/make_admin.php');

check('there is a tool for it', is_file(__DIR__ . '/../tools/make_admin.php'));

check('it refuses an address with no account', str_contains($tool, 'No account for'));

check('it can revoke as well as grant', str_contains($tool, '--revoke'));

check('it is command line only, like the other tools',
    str_contains($tool, "PHP_SAPI !== 'cli'"));

check('it does not weaken the rule that nothing in the INTERFACE grants it',
    (function () {
        // tools/ is not app/ or includes/. The scan that guards this lives
        // further down and covers exactly those two directories.
        return !is_file(__DIR__ . '/../app/actions/make_admin.php');
    })());

section('Telling a learner how it has been');

$support  = (string) file_get_contents(__DIR__ . '/../app/support.php');
$endpoint = (string) file_get_contents(__DIR__ . '/../app/actions/feedback_submit.php');

check('the Support page has an experience box',
    str_contains($support, 'How has EduFlex been for you?'));

check('it sits below the request list', (function () use ($support) {
    return strpos($support, 'Your requests') < strpos($support, 'How has EduFlex been for you?');
})());

check('it posts to its own endpoint',
    str_contains($support, "actions/feedback_submit.php"));

check('that endpoint requires a signed-in learner',
    str_contains($endpoint, 'auth_require_login('));

check('and checks CSRF and the method', (function () use ($endpoint) {
    return str_contains($endpoint, 'csrf_check(')
        && str_contains($endpoint, "REQUEST_METHOD'] !== 'POST'");
})());

check('it stores through support_create(), not a raw INSERT', (function () use ($endpoint) {
    // So the length rules, the type whitelist and the escaping are the same
    // ones the request form already uses.
    return str_contains($endpoint, 'support_create(')
        && !str_contains($endpoint, 'INSERT INTO');
})());

check('it files as a suggestion, so it reaches Manage Feedback',
    str_contains($endpoint, "'suggestion'"));

check('a suggestion really is one of the feedback types',
    in_array('suggestion', ADMIN_FEEDBACK_TYPES, true));

check('a rejected message is handed back rather than lost',
    str_contains($endpoint, "flash_set('feedback_draft'"));

check('its flash keys are its own', (function () use ($endpoint, $support) {
    // Sharing keys with the request form means one failure wipes the other's
    // message and the learner sees the wrong thing under the wrong box.
    return str_contains($endpoint, 'feedback_ok')
        && str_contains($endpoint, 'feedback_error')
        && str_contains($support, '$feedbackOk')
        && str_contains($support, '$feedbackError');
})());

section('The schema change reached all three files');

check('01_schema.sql has is_admin, so a fresh install gets it', (function () {
    $sql = (string) file_get_contents(__DIR__ . '/../database/01_schema.sql');
    return str_contains($sql, 'is_admin');
})());

check('02_migrations.sql adds it to an existing database', (function () {
    $sql = (string) file_get_contents(__DIR__ . '/../database/02_migrations.sql');
    return str_contains($sql, 'is_admin');
})());

check('tools/migrate.php knows about it', (function () {
    $php = (string) file_get_contents(__DIR__ . '/../tools/migrate.php');
    return str_contains($php, "'user', 'is_admin'");
})());

check('it defaults to 0, so no existing account becomes an admin', (function () {
    $sql = (string) file_get_contents(__DIR__ . '/../database/02_migrations.sql');
    return (bool) preg_match('/is_admin\s+TINYINT\(1\)\s+NOT NULL\s+DEFAULT\s+0/i', $sql);
})());

check('nothing in the interface can grant it', (function () {
    // The flag is set by hand in phpMyAdmin. A promote button, an admin
    // registration or a "first user is admin" rule would each be a way for a
    // bug to hand a learner the queue.
    //
    // Comments are stripped first. includes/admin.php documents the manual
    // UPDATE in its header, and reading the raw file finds that sentence and
    // reports the opposite of the truth.
    $files = array_merge(
        glob(__DIR__ . '/../app/actions/*.php') ?: [],
        glob(__DIR__ . '/../includes/*.php') ?: []
    );
    foreach ($files as $file) {
        $code = php_strip_whitespace($file);
        if (preg_match('/UPDATE\s+user\s+SET[^;]*is_admin/i', $code)) { return false; }
        if (preg_match('/INSERT\s+INTO\s+user\b[^;]*is_admin/i', $code)) { return false; }
    }
    return true;
})());

check('and the scan would actually catch one if it existed', (function () {
    // A scan that cannot fail proves nothing. This feeds it the statement it is
    // looking for and checks it fires.
    $tmp = sys_get_temp_dir() . '/ef_admin_scan_' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($tmp,
        "<?php db()->exec(\"UPDATE user SET is_admin = 1 WHERE user_id = 1\");");
    $code = php_strip_whitespace($tmp);
    @unlink($tmp);
    return (bool) preg_match('/UPDATE\s+user\s+SET[^;]*is_admin/i', $code);
})());

echo "\n===========================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
