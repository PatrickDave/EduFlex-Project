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
  is_admin INTEGER NOT NULL DEFAULT 0,
  email_verified_at TEXT NULL,
  program TEXT NOT NULL DEFAULT 'BS Information Technology',
  year_level INTEGER NOT NULL DEFAULT 1,
  avatar_path TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE trusted_browser (token_hash TEXT, user_id INTEGER);
CREATE TABLE email_verification (user_id INTEGER);
CREATE TABLE support_request (
  request_id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL, request_type TEXT NOT NULL,
  subject TEXT NOT NULL, message TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'open',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE support_reply (
  reply_id INTEGER PRIMARY KEY AUTOINCREMENT,
  request_id INTEGER NOT NULL, author_id INTEGER NULL,
  from_team INTEGER NOT NULL DEFAULT 0, message TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE learning_resource (
  resource_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
  title TEXT NOT NULL DEFAULT '');
CREATE TABLE topic_progress (
  topic_progress_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
  topic_name TEXT NOT NULL DEFAULT '');
CREATE TABLE activity_attempt (
  attempt_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL);
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

check('there is no exception left, not even for Settings', (function () use ($build) {
    // There was one, because app/settings.php was the only place the team
    // could change its own password. app/admin_settings.php does that now, so
    // the separation is complete: no learner page at all.
    return !str_contains($build, "\$active !== 'settings'");
})());

check('the admin nav points at its own Settings, not the learner one', (function () {
    $sidebar = (string) file_get_contents(__DIR__ . '/../partials/sidebar.php');
    $at = strpos($sidebar, 'if ($adminArea) {');
    $block = substr($sidebar, (int) $at, 700);
    return str_contains($block, "'href' => 'admin_settings.php'")
        && !str_contains($block, "'href' => 'settings.php'");
})());

check('the learner Settings page is now refused like any other learner page',
    (function () {
        // $active is 'settings', which does not start with 'admin', so the
        // guard fires. Before admin_settings.php existed it was excepted.
        $page = (string) file_get_contents(__DIR__ . '/../app/settings.php');
        return str_contains($page, "redirect('admin.php')")
            && str_contains($page, "\$active = 'settings'");
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

section('The team has its own Settings');

/* 2 November 2026. Clicking Settings as an admin landed on app/settings.php,
   a learner screen with Personal details, a plan card and an export of "your"
   learning, none of which a shared team address has. Worse, $active was
   'settings', so the sidebar rendered the LEARNER nav and the help desk
   vanished mid-session. That is the inconsistency Patrick reported. */

$adminSettings = (string) file_get_contents(__DIR__ . '/../app/admin_settings.php');

check('there is a team settings page', is_file(__DIR__ . '/../app/admin_settings.php'));

check('it is an admin page, so the sidebar stays the help desk nav',
    str_contains($adminSettings, "\$active = 'admin_settings'"));

check('and it is gated like every other admin page',
    str_contains($adminSettings, 'admin_require()'));

check('profile picture stayed', str_contains($adminSettings, 'Profile picture'));
check('change password stayed',  str_contains($adminSettings, 'Change password'));
check('delete my account stayed', str_contains($adminSettings, 'Delete my account'));

check('personal details went', (function () use ($adminSettings) {
    // The page's own comment names what was removed, so reading the raw file
    // finds that sentence and reports the opposite of the truth. Same trap as
    // the is_admin scan further down.
    return !str_contains(php_strip_whitespace(__DIR__ . '/../app/admin_settings.php'),
                         'Personal details');
})());

check('"Your data" became Users', (function () use ($adminSettings) {
    return !str_contains($adminSettings, 'Your data')
        && str_contains($adminSettings, '>Users<');
})());

check('the learner plan card is not on it',
    !str_contains($adminSettings, 'Your plan'));

section('Users: acting on a participant');

check('admin_users() lists learners', count(admin_users()) >= 1);

check('and never a team account', (function () {
    foreach (admin_users() as $u) {
        $row = db()->query('SELECT is_admin FROM user WHERE user_id = '
                           . (int) $u['user_id'])->fetch();
        if ($row !== false && (int) $row['is_admin'] === 1) { return false; }
    }
    return true;
})());

check('admin_user() refuses a team account', (function () {
    // Otherwise a crafted id could export or delete another admin through a
    // screen meant for participants.
    $adminId = (int) db()->query('SELECT user_id FROM user WHERE is_admin = 1 LIMIT 1')
                        ->fetchColumn();
    return $adminId > 0 && admin_user($adminId) === null;
})());

check('and a learner that does not exist', admin_user(999999) === null);

$exportEndpoint = (string) file_get_contents(__DIR__ . '/../app/actions/admin_user_export.php');
$deleteEndpoint = (string) file_get_contents(__DIR__ . '/../app/actions/admin_user_delete.php');

check('both endpoints are gated', (function () use ($exportEndpoint, $deleteEndpoint) {
    return str_contains($exportEndpoint, 'admin_require()')
        && str_contains($deleteEndpoint, 'admin_require()');
})());

check('both check CSRF and the method', (function () use ($exportEndpoint, $deleteEndpoint) {
    foreach ([$exportEndpoint, $deleteEndpoint] as $src) {
        if (!str_contains($src, 'csrf_check(')) { return false; }
        if (!str_contains($src, "REQUEST_METHOD'] !== 'POST'")) { return false; }
    }
    return true;
})());

check('both resolve the target through admin_user(), not a raw id',
    (function () use ($exportEndpoint, $deleteEndpoint) {
        return str_contains($exportEndpoint, 'admin_user(')
            && str_contains($deleteEndpoint, 'admin_user(');
    })());

check('deleting still needs the address typed', (function () use ($deleteEndpoint) {
    // auth_delete_account() refuses unless it matches. That check was written
    // for a learner deleting themselves and is worth more here, where the
    // person typing is not the person being deleted.
    return str_contains($deleteEndpoint, 'confirm_email')
        && str_contains($deleteEndpoint, 'auth_delete_account(');
})());

check('and the mismatch message does not say "your own email"', (function () use ($deleteEndpoint) {
    // auth_delete_account() says exactly that, which is right for the learner
    // page and wrong here: the address belongs to somebody else.
    return str_contains($deleteEndpoint, 'your own email')
        && str_contains($deleteEndpoint, 'Type %s exactly');
})());

check('export reuses auth_export_data(), not a second query',
    str_contains($exportEndpoint, 'auth_export_data('));

section('Shared endpoints send each role back where it came from');

check('there is one place that decides which settings page is whose',
    function_exists('auth_settings_page'));

check('it routes an admin to the team page',
    auth_settings_page(['is_admin' => 1]) === 'admin_settings.php');

check('and a learner to their own',
    auth_settings_page(['is_admin' => 0]) === 'settings.php');

check('the four shared endpoints use it', (function () {
    // change_password, save_avatar, delete_avatar and delete_account are
    // posted to by BOTH settings pages. They all redirected to settings.php,
    // and for an admin the SHELL guard then bounced that to the help desk,
    // where nothing reads flash_get('password_ok'). The password really did
    // change and the screen said nothing.
    foreach (['change_password', 'save_avatar', 'delete_avatar', 'delete_account'] as $f) {
        $src = (string) file_get_contents(__DIR__ . '/../app/actions/' . $f . '.php');
        if (!str_contains($src, 'auth_settings_page(')) { return false; }
        if (str_contains($src, "redirect('../settings.php')")) { return false; }
    }
    return true;
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

/* ------------------------------------------------------ replying to learners */

/* 19 November 2026. The team can now answer a request, not just move its
   status. Request 1 is learner 2's open bug report; patrick (user 1) is the
   team account. */

section('The team replies');

$pdo->exec('DELETE FROM notification');
$pdo->exec('DELETE FROM support_reply');
$pdo->exec("UPDATE support_request SET status = 'open' WHERE request_id = 1");

check('an untouched open request needs a reply', admin_needs_reply(admin_request(1)));

$r = admin_reply(1, 1, 'Thanks. Which browser were you using?');
check('the reply is accepted',                 $r['ok'] === true && $r['error'] === null);
check('replying to an open request starts it', $r['status'] === 'in_progress'
    && (string) admin_request(1)['status'] === 'in_progress');
check('the reply is stored as the team\'s', (function () {
    $rows = support_replies(1);
    return count($rows) === 1 && (int) $rows[0]['from_team'] === 1
        && (int) $rows[0]['author_id'] === 1;
})());
check('the queue row counts it', (int) admin_request(1)['reply_count'] === 1);
check('and the next move is now the learner\'s', !admin_needs_reply(admin_request(1)));

check('the learner is told, exactly once', (int) $pdo->query(
    "SELECT COUNT(*) FROM notification WHERE user_id = 2 AND notification_type = 'support'")
    ->fetchColumn() === 1);
check('the notification quotes the reply', (function () use ($pdo) {
    $msg = (string) $pdo->query("SELECT message FROM notification WHERE user_id = 2")->fetchColumn();
    return str_contains($msg, 'Which browser') && str_contains($msg, 'Upload fails');
})());
check('nobody else is told anything', (int) $pdo->query(
    "SELECT COUNT(*) FROM notification WHERE user_id <> 2")->fetchColumn() === 0);

section('The learner answers, and it comes back to the team');

support_learner_reply(2, 1, 'Chrome, on Windows.');
check('a learner reply puts it back on the team', admin_needs_reply(admin_request(1)));
check('the team is not sent a notification for it', (int) $pdo->query(
    'SELECT COUNT(*) FROM notification WHERE user_id = 1')->fetchColumn() === 0);
check('the count of requests needing a reply includes it', admin_needs_reply_count() >= 1);

section('Reply and resolve in one step');

$pdo->exec('DELETE FROM notification');
$r = admin_reply(1, 1, 'Fixed in the latest version. Please try again.', true);
check('it is resolved',  $r['ok'] && (string) admin_request(1)['status'] === 'resolved');
check('a resolved request no longer needs a reply', !admin_needs_reply(admin_request(1)));
check('the learner gets ONE notification, not one per change', (int) $pdo->query(
    'SELECT COUNT(*) FROM notification WHERE user_id = 2')->fetchColumn() === 1);
check('and it says the request is resolved', str_contains(
    (string) $pdo->query('SELECT message FROM notification WHERE user_id = 2')->fetchColumn(),
    'Resolved'));

section('What the team cannot do');

$before = (int) $pdo->query('SELECT COUNT(*) FROM support_reply')->fetchColumn();
check('an empty reply is refused',     admin_reply(1, 1, '   ')['ok'] === false);
check('an oversized reply is refused',
    admin_reply(1, 1, str_repeat('x', SUPPORT_MESSAGE_MAX + 1))['ok'] === false);
check('a reply to a missing request is refused',
    admin_reply(1, 9999, 'Hello there.')['ok'] === false);
check('and it says so without a status', admin_reply(1, 9999, 'Hello there.')['status'] === '');
check('none of those wrote anything',
    (int) $pdo->query('SELECT COUNT(*) FROM support_reply')->fetchColumn() === $before);

$pdo->exec("UPDATE support_request SET status = 'closed' WHERE request_id = 3");
check('the team CAN still write on a closed request',
    // Closing is the team's call; saying a last word after it is too.
    admin_reply(1, 3, 'Closing this, thank you for the suggestion.')['ok'] === true);
check('and that does not reopen it', (string) admin_request(3)['status'] === 'closed');

section('Needs a reply, case by case');

check('nothing closed or resolved ever needs one', !admin_needs_reply(
    ['status' => 'resolved', 'reply_count' => 0, 'last_from_team' => null])
    && !admin_needs_reply(['status' => 'closed', 'reply_count' => 3, 'last_from_team' => 0]));
check('open with no replies does',
    admin_needs_reply(['status' => 'open', 'reply_count' => 0, 'last_from_team' => null]));
check('in progress, learner spoke last, does',
    admin_needs_reply(['status' => 'in_progress', 'reply_count' => 2, 'last_from_team' => '0']));
check('in progress, team spoke last, does not',
    // MySQL returns "1", SQLite 1. Both must read as the team.
    !admin_needs_reply(['status' => 'in_progress', 'reply_count' => 2, 'last_from_team' => '1'])
    && !admin_needs_reply(['status' => 'in_progress', 'reply_count' => 2, 'last_from_team' => 1]));

section('The reply door is locked too');

$replyAction = (string) file_get_contents(__DIR__ . '/../app/actions/admin_reply.php');
$statusAction = (string) file_get_contents(__DIR__ . '/../app/actions/admin_status.php');

check('the reply action calls admin_require()', str_contains($replyAction, 'admin_require()'));
check('before it reads anything from the POST', (function () use ($replyAction) {
    $gate = strpos($replyAction, 'admin_require()');
    $post = strpos($replyAction, '$_POST');
    return $gate !== false && $post !== false && $gate < $post;
})());
check('it refuses anything but POST', str_contains($replyAction, "REQUEST_METHOD'] !== 'POST'"));
check('it checks CSRF',               str_contains($replyAction, 'csrf_check('));
check('the author is the signed-in admin, never a form field',
    str_contains($replyAction, "auth_user()['user_id']") && !str_contains($replyAction, "\$_POST['user_id']"));
check('it redirects only to a page built from an integer id',
    // No "back" field at all, so there is nothing to turn into an open redirect.
    !str_contains($replyAction, "\$_POST['back']")
    && str_contains($replyAction, "(int) (\$_POST['request_id']"));
check('the status action may return to the conversation page',
    str_contains($statusAction, "'admin_thread.php'"));
check('and builds that return from the cast id, not the POST',
    str_contains($statusAction, "['id' => \$requestId]"));

check('the conversation page is gated before any output', (function () {
    $src  = (string) @file_get_contents(__DIR__ . '/../app/admin_thread.php');
    $gate = strpos($src, 'admin_require()');
    $html = strpos($src, '<!DOCTYPE html>');
    return $gate !== false && $html !== false && $gate < $html;
})());
check('build_pages.py is its source', (function () {
    $build = (string) file_get_contents(__DIR__ . '/../build_pages.py');
    return str_contains($build, '"admin_thread.php"');
})());
check('it uses the help desk nav, not the learner one', (function () {
    // An active key not starting with "admin" would send the team account to
    // admin.php from the SHELL guard, and the page could never be reached.
    $build = (string) file_get_contents(__DIR__ . '/../build_pages.py');
    return (bool) preg_match('/\("admin_thread\.php",\s*"[^"]+",\s*"admin/', $build);
})());

section('Both sides render the same thread');

check('the conversation is one partial, included by both pages', (function () {
    $a = (string) @file_get_contents(__DIR__ . '/../app/admin_thread.php');
    $l = (string) @file_get_contents(__DIR__ . '/../app/support_thread.php');
    return str_contains($a, 'partials/support_thread.php')
        && str_contains($l, 'partials/support_thread.php');
})());
check('the support thread does not borrow the companion\'s class names', (function () {
    // 20 November 2026: the support partial used .ef-thread and .ef-msg, which
    // the AI companion already owns. The support styles leaked into the chat:
    // every message got a white bordered box and the learner's own questions
    // moved to the left. Renamed to .ef-conv / .ef-conv-msg.
    $p = (string) file_get_contents(__DIR__ . '/../partials/support_thread.php');
    return !preg_match('/class="ef-thread"|class="ef-msg[\s"<-]/', $p)
        && str_contains($p, 'class="ef-conv"');
})());

check('the partial escapes every message', (function () {
    $p = (string) file_get_contents(__DIR__ . '/../partials/support_thread.php');
    return str_contains($p, "nl2br(e((string) \$m['message']))") && !str_contains($p, '<?= $m[');
})());

check('a reply survives as data, not markup', (function () {
    admin_reply(1, 2, '<script>alert(1)</script> & done');
    $rows = support_replies(2);
    $last = end($rows);
    return (string) $last['message'] === '<script>alert(1)</script> & done'
        && e((string) $last['message']) === '&lt;script&gt;alert(1)&lt;/script&gt; &amp; done';
})());

/* ----------------------------------------------------- manage user accounts */

/* 19 November 2026. Users so far: 1 Patrick (team), 2 A Learner, 3 Another.
   Two more are added here so search and the filters have something to tell
   apart. */

$pdo->exec("INSERT INTO user (full_name, email, is_admin, account_status, email_verified_at) VALUES
  ('Maria Santos',   'maria@example.com',   0, 'active',   '2026-11-01 08:00:00'),
  ('Juan Dela Cruz', 'juan_50%@example.com', 0, 'inactive', NULL)");
$pdo->exec("UPDATE user SET email_verified_at = '2026-11-01 08:00:00' WHERE user_id IN (1, 2)");

$ids = static fn(array $rows): array => array_map(static fn(array $r): int => (int) $r['user_id'], $rows);

section('Viewing and searching accounts');

check('every account is listed, team included', count(admin_accounts()) === 5);
check('newest first by default', $ids(admin_accounts())[0] === 5);
check('oldest first when asked', $ids(admin_accounts('', '', '', '', 'oldest'))[0] === 1);
check('by name when asked', (string) admin_accounts('', '', '', '', 'name')[0]['full_name'] === 'A Learner');
check('an unknown sort falls back to newest, not to the URL',
    $ids(admin_accounts('', '', '', '', 'user_id; DROP TABLE user'))[0] === 5);

check('search matches part of a name', $ids(admin_accounts('santos')) === [4]);
check('search is case-insensitive',    $ids(admin_accounts('MARIA')) === [4]);
check('search matches an email',       $ids(admin_accounts('other@')) === [3]);
check('a number also matches the user id', in_array(2, $ids(admin_accounts('2')), true));
check('a search with no match returns nothing', admin_accounts('nobody-at-all') === []);
check('a literal % is searched for, not used as a wildcard',
    // Without escaping, "50%" would match anything containing "50".
    $ids(admin_accounts('50%')) === [5]);
check('a literal _ is too', $ids(admin_accounts('n_5')) === [5]);
check('a quote in the search is just text', admin_accounts("o'brien") === []);

section('Filtering accounts');

check('active only',      !in_array(5, $ids(admin_accounts('', 'active')), true)
                          && count(admin_accounts('', 'active')) === 4);
check('deactivated only', $ids(admin_accounts('', 'inactive')) === [5]);
check('verified only',    count(admin_accounts('', '', 'yes')) === 3);
check('not verified only', $ids(admin_accounts('', '', 'no', '', 'oldest')) === [3, 5]);
check('team only',        $ids(admin_accounts('', '', '', 'team')) === [1]);
check('learners only',    !in_array(1, $ids(admin_accounts('', '', '', 'learner')), true));
check('filters combine',  $ids(admin_accounts('juan', 'inactive', 'no', 'learner')) === [5]);
check('an unknown filter value is ignored, not trusted',
    count(admin_accounts('', 'banned', 'maybe', 'root')) === 5);
check('each row carries its activity counts', (function () {
    $row = admin_accounts('learner@')[0];
    return array_key_exists('materials', $row) && array_key_exists('requests', $row)
        && (int) $row['requests'] === 2;
})());

$sum = admin_account_summary();
check('the summary counts every account', $sum['total'] === 5);
check('and splits active from deactivated', $sum['active'] === 4 && $sum['inactive'] === 1);
check('and counts the unverified and the team', $sum['unverified'] === 2 && $sum['team'] === 1);

section('Viewing one account');

check('a learner\'s details load', (admin_account(4)['email'] ?? null) === 'maria@example.com');
check('a team account\'s details load too, for viewing', (int) (admin_account(1)['is_admin'] ?? 0) === 1);
check('a missing account is null', admin_account(999) === null);
check('their support requests are listed', count(admin_account_requests(2)) === 2);
check('and nobody else\'s', admin_account_requests(4) === []);
check('the password hash is never selected', !array_key_exists('password_hash', admin_account(2) ?? []));

section('Editing an account');

$pdo->exec('DELETE FROM notification');
$r = admin_update_account(4, '  Maria L. Santos ', 'maria@example.com', 'BS Computer Science', 3);
check('a valid edit is saved', $r['ok'] && $r['changed'] && !$r['email_changed']);
check('the values are stored, trimmed', (function () {
    $a = admin_account(4);
    return $a['full_name'] === 'Maria L. Santos' && $a['program'] === 'BS Computer Science'
        && (int) $a['year_level'] === 3;
})());
check('an unchanged email keeps its verification', admin_account(4)['email_verified_at'] !== null);
check('the learner is notified of the change', (int) $pdo->query(
    'SELECT COUNT(*) FROM notification WHERE user_id = 4')->fetchColumn() === 1);

$pdo->exec('DELETE FROM notification');
$r = admin_update_account(4, 'Maria L. Santos', 'maria@example.com', 'BS Computer Science', 3);
check('saving with nothing changed writes nothing', $r['ok'] && !$r['changed']);
check('and notifies nobody', (int) $pdo->query('SELECT COUNT(*) FROM notification')->fetchColumn() === 0);

$r = admin_update_account(4, 'Maria L. Santos', 'MARIA.NEW@Example.com', 'BS Computer Science', 3);
check('an email change is saved, lower-cased', $r['ok'] && $r['email_changed']
    && admin_account(4)['email'] === 'maria.new@example.com');
check('and clears the verification, so the new address must be proved',
    admin_account(4)['email_verified_at'] === null);

check('a taken email is refused', admin_update_account(4, 'Maria', 'learner@example.com', 'BSIT', 1)
    ['errors']['email'] === 'Another account already uses that email address.');
check('an invalid email is refused', isset(admin_update_account(4, 'Maria', 'not-an-email', 'BSIT', 1)['errors']['email']));
check('an empty name is refused',    isset(admin_update_account(4, '  ', 'maria.new@example.com', 'BSIT', 1)['errors']['full_name']));
check('an overlong name is refused',
    isset(admin_update_account(4, str_repeat('a', 151), 'maria.new@example.com', 'BSIT', 1)['errors']['full_name']));
check('an empty programme is refused', isset(admin_update_account(4, 'Maria', 'maria.new@example.com', '', 1)['errors']['program']));
check('year 0 and year 6 are refused',
    isset(admin_update_account(4, 'Maria', 'maria.new@example.com', 'BSIT', 0)['errors']['year_level'])
    && isset(admin_update_account(4, 'Maria', 'maria.new@example.com', 'BSIT', 6)['errors']['year_level']));
check('a refused edit changes nothing', admin_account(4)['full_name'] === 'Maria L. Santos');

check('a team account cannot be edited here', (function () use ($pdo) {
    $res = admin_update_account(1, 'Hijacked', 'evil@example.com', 'BSIT', 1);
    return $res['ok'] === false && admin_account(1)['email'] === 'patrick@example.com';
})());
check('a missing account cannot be edited', admin_update_account(999, 'X', 'x@example.com', 'BSIT', 1)['ok'] === false);

check('an edit inside a caller\'s transaction leaves that transaction alone', (function () use ($pdo) {
    // 19 November 2026: it used to roll back the CALLER's transaction when its
    // own beginTransaction() failed, so the caller's later writes committed.
    $pdo->beginTransaction();
    $res = admin_update_account(4, 'Inside A Transaction', 'maria.new@example.com', 'BSIT', 2);
    $still = $pdo->inTransaction();
    $pdo->rollBack();
    return $res['ok'] === true && $still
        && admin_account(4)['full_name'] === 'Maria L. Santos';
})());

section('Activating and deactivating');

$pdo->exec('DELETE FROM notification');
$r = admin_set_account_status(4, 'inactive');
check('a learner can be deactivated', $r['ok'] && $r['changed']
    && admin_account(4)['account_status'] === 'inactive');
check('deactivation deletes nothing', admin_account(4) !== null);
check('deactivation writes no notification they could not read', (int) $pdo->query(
    'SELECT COUNT(*) FROM notification WHERE user_id = 4')->fetchColumn() === 0);
check('deactivating twice reports no change', admin_set_account_status(4, 'inactive')['changed'] === false);

$r = admin_set_account_status(4, 'active');
check('and activated again', $r['ok'] && $r['changed'] && admin_account(4)['account_status'] === 'active');
check('reactivation is announced', (int) $pdo->query(
    'SELECT COUNT(*) FROM notification WHERE user_id = 4')->fetchColumn() === 1);

check('a legacy status such as suspended counts as not active', (function () use ($pdo) {
    $pdo->exec("UPDATE user SET account_status = 'suspended' WHERE user_id = 3");
    $ok = admin_account_status_label('suspended') === 'Deactivated'
        && in_array(3, $ids = array_map(static fn($r) => (int) $r['user_id'], admin_accounts('', 'inactive')), true)
        && admin_set_account_status(3, 'active')['changed'] === true;
    return $ok && admin_account(3)['account_status'] === 'active';
})());

check('a status outside the list is refused', admin_set_account_status(4, 'banned')['ok'] === false);
check('a team account cannot be deactivated here', (function () {
    $res = admin_set_account_status(1, 'inactive');
    return $res['ok'] === false && admin_account(1)['account_status'] === 'active';
})());
check('a missing account cannot be deactivated', admin_set_account_status(999, 'inactive')['ok'] === false);

section('A deactivated session does not survive');

check('auth_user() checks account_status on every request', (function () {
    // Until 19 November 2026 only auth_is_logged_in() checked it, and only
    // with email verification on. auth_user() is on every gated path.
    $src  = php_strip_whitespace(__DIR__ . '/../includes/auth.php');
    $from = strpos($src, 'function auth_user');
    $to   = strpos($src, 'function auth_require_login');
    $body = $from !== false && $to !== false ? substr($src, $from, $to - $from) : '';
    return str_contains($body, "account_status'] !== 'active'") && str_contains($body, 'auth_logout()');
})());

section('The account screens are locked and wired');

$build   = (string) file_get_contents(__DIR__ . '/../build_pages.py');
$sidebar = (string) file_get_contents(__DIR__ . '/../partials/sidebar.php');

foreach (['admin_users.php', 'admin_user.php'] as $f) {
    check("$f is generated and gated before any output", (function () use ($f, $build) {
        $src  = (string) @file_get_contents(__DIR__ . '/../app/' . $f);
        $gate = strpos($src, 'admin_require()');
        $html = strpos($src, '<!DOCTYPE html>');
        return str_contains($build, '"' . $f . '"') && str_contains($src, 'GENERATED FILE')
            && $gate !== false && $html !== false && $gate < $html;
    })());
}

foreach (['admin_user_update.php', 'admin_user_status.php', 'admin_user_delete.php', 'admin_user_export.php'] as $f) {
    $src = (string) file_get_contents(__DIR__ . '/../app/actions/' . $f);
    check("$f calls admin_require() before reading the POST",
        ($g = strpos($src, 'admin_require()')) !== false && $g < strpos($src, '$_POST'));
    check("$f requires POST and CSRF",
        str_contains($src, "REQUEST_METHOD'] !== 'POST'") && str_contains($src, 'csrf_check('));
    check("$f never redirects to a page named in the POST",
        !preg_match('/redirect\(\s*\$_POST/', $src) && !str_contains($src, "\$_POST['back']"));
}

check('the sidebar shows Manage User Accounts in the help desk nav',
    str_contains($sidebar, "'admin_users'") && str_contains($sidebar, 'Manage User Accounts'));
check('it has its own inline icon', (bool) preg_match("/'admin_users'\s*=>\s*'<circle/", $sidebar));
check('deactivate and delete ask for confirmation in the browser', (function () {
    $src = (string) file_get_contents(__DIR__ . '/../app/admin_user.php');
    return substr_count($src, 'data-confirm=') >= 2;
})());
check('and the confirm handler exists in eduflex.js',
    str_contains((string) file_get_contents(__DIR__ . '/../assets/js/eduflex.js'), "form[data-confirm]"));
check('delete still requires the typed email on the account screen',
    str_contains((string) file_get_contents(__DIR__ . '/../app/admin_user.php'), 'name="confirm_email"'));
check('team accounts get no edit, status or delete form', (function () {
    // The forms sit inside the else branch of the $isTeam check.
    $src = (string) file_get_contents(__DIR__ . '/../app/admin_user.php');
    $team = strpos($src, '<?php if ($isTeam): ?>');
    $else = strpos($src, '<?php else: ?>', (int) $team);
    $form = strpos($src, 'actions/admin_user_update.php');
    return $team !== false && $else !== false && $form > $else;
})());

echo "\n===========================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
