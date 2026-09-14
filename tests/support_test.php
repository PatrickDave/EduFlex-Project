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

echo "\n=====================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
