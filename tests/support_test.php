<?php
/**
 * EduFlex — support request test suite.
 *
 *     php tests/support_test.php
 *
 * Runs against an in-memory database. No API key, no network, no server.
 *
 * The check that matters most here is that request_type is decided in PHP and
 * never taken from the POST body. The column is a VARCHAR(20), so without the
 * whitelist a crafted request could store anything at all in it, and the page
 * renders the type as a label.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/support.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "  PASS  $label\n"; }
    else            { $failed++; echo "  FAIL  $label\n"; }
}
function section(string $name): void { echo "\n$name\n"; }

echo "EduFlex support suite\n=====================\n";

/* ----------------------------------------------------------- test database */

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("
CREATE TABLE support_request (
  request_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
  request_type TEXT NOT NULL, subject TEXT NOT NULL, message TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'open',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE user (
  user_id INTEGER PRIMARY KEY AUTOINCREMENT, full_name TEXT NOT NULL DEFAULT '');
CREATE TABLE support_reply (
  reply_id INTEGER PRIMARY KEY AUTOINCREMENT,
  request_id INTEGER NOT NULL, author_id INTEGER NULL,
  from_team INTEGER NOT NULL DEFAULT 0, message TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
");
db_set_connection($pdo);

$goodMessage = 'The practice runner showed a blank page after I answered question four.';

/* ------------------------------------------------------------- the happy path */

section('Recording a request');

$created = support_create(1, 'bug', 'Practice runner went blank', $goodMessage);
check('the request is recorded',   $created['ok'] === true);
check('it returns an id',          is_int($created['request_id']) && $created['request_id'] > 0);
check('no errors are reported',    $created['errors'] === []);
check('one row was written',
    (int) $pdo->query('SELECT COUNT(*) FROM support_request')->fetchColumn() === 1);
check('it opens as open',
    (string) $pdo->query('SELECT status FROM support_request LIMIT 1')->fetchColumn() === 'open');
check('the type is stored as chosen',
    (string) $pdo->query('SELECT request_type FROM support_request LIMIT 1')->fetchColumn() === 'bug');

check('the subject and message are stored verbatim', (function () use ($pdo, $goodMessage) {
    $row = $pdo->query('SELECT subject, message FROM support_request LIMIT 1')->fetch();
    return $row['subject'] === 'Practice runner went blank' && $row['message'] === $goodMessage;
})());

check('surrounding whitespace is trimmed', (function () use ($pdo, $goodMessage) {
    support_create(1, 'question', '   Spaced out   ', '   ' . $goodMessage . '   ');
    $row = $pdo->query('SELECT subject, message FROM support_request
                         ORDER BY request_id DESC LIMIT 1')->fetch();
    return $row['subject'] === 'Spaced out' && $row['message'] === $goodMessage;
})());

/* ------------------------------------------------- the type is not from POST */

section('The request type is decided in PHP, not by the browser');

$before = (int) $pdo->query('SELECT COUNT(*) FROM support_request')->fetchColumn();

check('an unknown type is refused',
    isset(support_create(1, 'refund', 'Subject here', $goodMessage)['errors']['request_type']));
check('an empty type is refused',
    isset(support_create(1, '', 'Subject here', $goodMessage)['errors']['request_type']));
check('a type that is really markup is refused',
    isset(support_create(1, '<script>x</script>', 'Subject here', $goodMessage)['errors']['request_type']));
check('a type that is really SQL is refused',
    isset(support_create(1, "bug'; DROP TABLE support_request; --", 'Subject here', $goodMessage)['errors']['request_type']));
check('none of those wrote a row',
    (int) $pdo->query('SELECT COUNT(*) FROM support_request')->fetchColumn() === $before);
check('the table survived',
    (int) $pdo->query('SELECT COUNT(*) FROM support_request')->fetchColumn() > 0);

check('every declared type is accepted', (function () {
    foreach (array_keys(SUPPORT_TYPES) as $type) {
        $r = support_create(1, $type, 'Subject for ' . $type,
            'A message long enough to pass the minimum length check.');
        if ($r['ok'] !== true) { return false; }
    }
    return true;
})());

/* ------------------------------------------------------------- validation */

section('Validation');

$before = (int) $pdo->query('SELECT COUNT(*) FROM support_request')->fetchColumn();

check('an empty subject is refused',
    isset(support_create(1, 'bug', '', $goodMessage)['errors']['subject']));
check('a whitespace-only subject is refused',
    isset(support_create(1, 'bug', '    ', $goodMessage)['errors']['subject']));
check('an over-long subject is refused',
    isset(support_create(1, 'bug', str_repeat('A', 256), $goodMessage)['errors']['subject']));
check('a subject of exactly 255 is accepted',
    support_create(1, 'bug', str_repeat('A', 255), $goodMessage)['ok'] === true);

check('an empty message is refused',
    isset(support_create(1, 'bug', 'Subject here', '')['errors']['message']));
check('a message with no detail in it is refused',
    isset(support_create(1, 'bug', 'Subject here', 'broken')['errors']['message']));
check('an over-long message is refused',
    isset(support_create(1, 'bug', 'Subject here',
        str_repeat('A', SUPPORT_MESSAGE_MAX + 1))['errors']['message']));
check('a message of exactly the maximum is accepted',
    support_create(1, 'bug', 'Subject here', str_repeat('A', SUPPORT_MESSAGE_MAX))['ok'] === true);

check('several problems are all reported at once', (function () {
    $errors = support_create(1, 'nope', '', 'x')['errors'];
    return isset($errors['request_type'], $errors['subject'], $errors['message']);
})());

check('a rejected request writes nothing', (function () use ($pdo) {
    $before = (int) $pdo->query('SELECT COUNT(*) FROM support_request')->fetchColumn();
    support_create(1, 'nope', '', 'x');
    return (int) $pdo->query('SELECT COUNT(*) FROM support_request')->fetchColumn() === $before;
})());

/* -------------------------------------------------------------- ownership */

section('A learner sees only their own requests');

$pdo->exec('DELETE FROM support_request');

support_create(1, 'bug', 'Learner one, first',  $goodMessage);
support_create(1, 'question', 'Learner one, second', $goodMessage);
support_create(2, 'account', 'Learner two, only',  $goodMessage);

check('each learner sees their own count',
    count(support_list(1)) === 2 && count(support_list(2)) === 1);
check('a learner with nothing sees nothing', support_list(999) === []);

check('no row belonging to anyone else is returned', (function () {
    foreach (support_list(1) as $r) {
        if (str_contains((string) $r['subject'], 'Learner two')) { return false; }
    }
    return true;
})());

check('the newest request comes first',
    (string) support_list(1)[0]['subject'] === 'Learner one, second');
check('the limit is respected',             count(support_list(1, 1)) === 1);
check('a limit of zero is clamped to one',  count(support_list(1, 0)) === 1);

/* ------------------------------------------------------------ presentation */

section('Presentation helpers');

check('a known type has a label',       support_type_label('bug') === 'Something is broken');
check('an unknown type falls back',     support_type_label('nonsense') === 'Other');
check('every declared type has a label', (function () {
    foreach (array_keys(SUPPORT_TYPES) as $type) {
        if (support_type_label($type) === 'Other') { return false; }
    }
    return true;
})());

check('a new request reads as recorded', support_status_label('open') === 'Recorded');
check('resolved uses the mastered band', support_status_band('resolved') === 'mastered');
check('open uses the weak band',         support_status_band('open') === 'weak');
check('an unknown status uses the neutral band',
    support_status_band('nonsense') === 'none');
check('every schema status has a band and a label', (function () {
    foreach (SUPPORT_STATUSES as $status) {
        if (!in_array(support_status_band($status), ['mastered','developing','weak','none'], true)) {
            return false;
        }
        if (support_status_label($status) === '') { return false; }
    }
    return true;
})());

/* ------------------------------------------------------- the conversation */

/* 19 November 2026. A request is now a thread. These are the learner's half of
   it: reading their own conversation, answering the team, and the status rules
   that follow from an answer. The team's half is in tests/admin_test.php. */

section('Reading one of your own requests');

$pdo->exec('DELETE FROM support_request');
$pdo->exec('DELETE FROM support_reply');
$mine   = (int) support_create(7, 'bug', 'Thread owner', $goodMessage)['request_id'];
$theirs = (int) support_create(8, 'bug', 'Someone else', $goodMessage)['request_id'];

check('the owner can load their request',
    (support_request_for_user(7, $mine)['subject'] ?? null) === 'Thread owner');
check('another learner cannot, by guessing the id',
    support_request_for_user(7, $theirs) === null);
check('a missing id reads the same as somebody else\'s',
    // A guessed id must not even confirm that a request exists.
    support_request_for_user(7, 99999) === null);
check('a new request has no replies', support_replies($mine) === []);
check('and the list says so', (int) support_list(7)[0]['reply_count'] === 0);

section('Validating a reply');

check('an empty reply is refused',      support_reply_error('') !== null);
check('whitespace alone is refused',    support_reply_error("   \n  ") !== null);
check('one character is refused',       support_reply_error('k') !== null);
check('a short real answer is accepted', support_reply_error('Thanks, fixed.') === null);
check('the maximum is accepted',
    support_reply_error(str_repeat('a', SUPPORT_MESSAGE_MAX)) === null);
check('one over the maximum is refused',
    support_reply_error(str_repeat('a', SUPPORT_MESSAGE_MAX + 1)) !== null);
check('the reply floor is lower than a new request\'s',
    // "Thanks, that worked" is a complete reply and is under fifteen characters
    // of real content once you allow for short ones like "Yes, still broken".
    SUPPORT_REPLY_MIN < SUPPORT_MESSAGE_MIN);

section('Answering the team');

$r = support_learner_reply(7, $mine, '  It still fails on the second page.  ');
check('the owner can reply',               $r['ok'] === true && $r['error'] === null);
check('an open request is not "reopened"', $r['reopened'] === false);
check('the reply is stored',               count(support_replies($mine)) === 1);
check('it is trimmed', (string) support_replies($mine)[0]['message'] === 'It still fails on the second page.');
check('it is marked as the learner\'s, not the team\'s',
    (int) support_replies($mine)[0]['from_team'] === 0);
check('the author is recorded', (int) support_replies($mine)[0]['author_id'] === 7);

$before = (int) $pdo->query('SELECT COUNT(*) FROM support_reply')->fetchColumn();
$x = support_learner_reply(7, $theirs, 'Writing into somebody else\'s thread.');
check('a learner cannot reply on another learner\'s request', $x['ok'] === false);
check('and nothing was written',
    (int) $pdo->query('SELECT COUNT(*) FROM support_reply')->fetchColumn() === $before);
check('the refusal does not admit the request exists',
    $x['error'] === support_learner_reply(7, 99999, 'Nothing here at all.')['error']);
check('an empty reply writes nothing', (function () use ($pdo, $mine, $before) {
    $res = support_learner_reply(7, $mine, '   ');
    return $res['ok'] === false
        && (int) $pdo->query('SELECT COUNT(*) FROM support_reply')->fetchColumn() === $before;
})());

section('What an answer does to the status');

$pdo->exec("UPDATE support_request SET status = 'resolved' WHERE request_id = $mine");
$r = support_learner_reply(7, $mine, 'Actually it is broken again.');
check('replying on a resolved request reopens it', $r['ok'] && $r['reopened'] === true);
check('and the status really is open again',
    (string) support_request_for_user(7, $mine)['status'] === 'open');

$pdo->exec("UPDATE support_request SET status = 'in_progress' WHERE request_id = $mine");
support_learner_reply(7, $mine, 'One more detail for you.');
check('replying while it is being looked at leaves it there',
    (string) support_request_for_user(7, $mine)['status'] === 'in_progress');

$pdo->exec("UPDATE support_request SET status = 'closed' WHERE request_id = $mine");
$countBefore = count(support_replies($mine));
$c = support_learner_reply(7, $mine, 'Is anybody there?');
check('a closed request takes no more replies', $c['ok'] === false);
check('and points the learner at a new request', str_contains((string) $c['error'], 'new request'));
check('nothing was written to the closed thread', count(support_replies($mine)) === $countBefore);
check('and it stays closed', (string) support_request_for_user(7, $mine)['status'] === 'closed');

section('The thread, in order');

check('replies come back oldest first', (function () use ($mine) {
    $ids = array_map(static fn(array $r): int => (int) $r['reply_id'], support_replies($mine));
    $sorted = $ids;
    sort($sorted);
    return $ids === $sorted && count($ids) >= 3;
})());
check('the list counts every reply', (int) support_list(7)[0]['reply_count'] === count(support_replies($mine)));
check('and none of them were the team\'s', (int) support_list(7)[0]['team_replies'] === 0);

$pdo->exec("INSERT INTO support_reply (request_id, author_id, from_team, message)
            VALUES ($mine, 1, 1, 'We found the cause.')");
check('a team reply is counted as the team\'s', (int) support_list(7)[0]['team_replies'] === 1);

section('Who the learner sees as the author');

check('their own message reads as You',
    support_reply_author(['from_team' => 0], false) === 'You');
check('the team reads as the project team, without a personal name',
    support_reply_author(['from_team' => 1, 'author_name' => 'Patrick Cagas'], false)
        === 'EduFlex project team');
check('the team sees its own member\'s name',
    support_reply_author(['from_team' => 1, 'author_name' => 'EduFlex Team'], true) === 'EduFlex Team');
check('a deleted team account still reads as the project team',
    // author_id is ON DELETE SET NULL, so the LEFT JOIN brings back no name.
    support_reply_author(['from_team' => 1, 'author_name' => null], true) === 'Project team');
check('the team sees the learner by name',
    support_reply_author(['from_team' => 0], true, 'A Learner') === 'A Learner');

section('The learner\'s pages are wired up');

$build = (string) file_get_contents(__DIR__ . '/../build_pages.py');
$replyAction = (string) file_get_contents(__DIR__ . '/../app/actions/support_reply.php');

check('build_pages.py generates the learner thread page',
    str_contains($build, '"support_thread.php"'));
check('the page checks ownership in SQL before any output', (function () {
    $src  = (string) @file_get_contents(__DIR__ . '/../app/support_thread.php');
    $gate = strpos($src, 'support_request_for_user(');
    $html = strpos($src, '<!DOCTYPE html>');
    return $gate !== false && $html !== false && $gate < $html;
})());
check('the reply action requires a signed-in learner', str_contains($replyAction, 'auth_require_login('));
check('the reply action refuses anything but POST',
    str_contains($replyAction, "REQUEST_METHOD'] !== 'POST'"));
check('the reply action checks CSRF', str_contains($replyAction, 'csrf_check('));
check('the reply action goes through support_learner_reply()',
    str_contains($replyAction, 'support_learner_reply('));
check('the reply action takes the user from the session, not the form',
    str_contains($replyAction, "\$user['user_id']") && !str_contains($replyAction, "\$_POST['user_id']"));

echo "\n=====================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
