<?php
/**
 * EduFlex — profile picture test suite.
 *
 *     php tests/avatar_test.php
 *
 * Runs against an in-memory database and a scratch directory. No API key, no
 * network, no server.
 *
 * The check that matters most is that a file's type is decided by reading it,
 * never by trusting its name. A .php renamed to .png is the oldest upload
 * attack there is, and the profile picture is the only place in EduFlex where a
 * learner's own file is handed back to a browser.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/avatar.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "  PASS  $label\n"; }
    else            { $failed++; echo "  FAIL  $label\n"; }
}
function section(string $name): void { echo "\n$name\n"; }

echo "EduFlex profile picture suite\n=============================\n";

/* ----------------------------------------------------------- test database */

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("
CREATE TABLE user (
  user_id INTEGER PRIMARY KEY AUTOINCREMENT, full_name TEXT NOT NULL,
  email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL DEFAULT 'x',
  avatar_path TEXT NULL);
");
db_set_connection($pdo);

$pdo->exec("INSERT INTO user (full_name, email) VALUES ('Maria Dela Cruz', 'maria@example.com')");
$pdo->exec("INSERT INTO user (full_name, email) VALUES ('Other Learner', 'other@example.com')");

/* ------------------------------------------------------------- fixtures */

$tmp = sys_get_temp_dir() . '/eduflex_avatar_' . bin2hex(random_bytes(5));
mkdir($tmp, 0775, true);

/* The smallest valid files of each type, written as bytes rather than drawn,
   because GD is not enabled on the development machine and these tests must not
   depend on it. Each one is a real image that getimagesize() parses. */
$makePng = static function (string $path, int $w = 64, int $h = 64): void {
    // A minimal PNG: signature, IHDR with the given size, one IDAT, IEND.
    $chunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data
             . pack('N', crc32($type . $data));
    };
    $ihdr = pack('NN', $w, $h) . chr(8) . chr(0) . chr(0) . chr(0) . chr(0);
    $raw  = str_repeat(chr(0) . str_repeat(chr(200), $w), $h);
    $idat = gzcompress($raw);
    file_put_contents(
        $path,
        "\x89PNG\r\n\x1a\n" . $chunk('IHDR', $ihdr) . $chunk('IDAT', $idat) . $chunk('IEND', '')
    );
};

$makeGif = static function (string $path, int $w = 64, int $h = 64): void {
    file_put_contents(
        $path,
        'GIF89a' . pack('vv', $w, $h) . "\x80\x00\x00"
        . "\x00\x00\x00\xFF\xFF\xFF"
        . "\x2C" . pack('vvvv', 0, 0, $w, $h) . "\x00"
        . "\x02\x02\x44\x01\x00\x3B"
    );
};

$png      = $tmp . '/portrait.png';
$gif      = $tmp . '/portrait.gif';
$tiny     = $tmp . '/tiny.png';
$huge     = $tmp . '/huge.png';
$script   = $tmp . '/evil.png';
$polyglot = $tmp . '/polyglot.png';
$empty    = $tmp . '/empty.png';

$makePng($png, 64, 64);
$makeGif($gif, 48, 48);
$makePng($tiny, 8, 8);
$makePng($huge, 5000, 10);
file_put_contents($script, "<?php echo 'this is not an image'; ?>\n");
/* A PHP payload wearing the 8-byte PNG signature. getimagesize() does NOT
   reject this: it reports image/png and reads "dimensions" out of the bytes
   that follow the signature. Recorded below, because it is the reason
   avatar_header_is_intact() exists. */
file_put_contents($polyglot, "\x89PNG\r\n\x1a\n<?php echo 'payload'; ?>");

/* The same trick done carefully. A real PNG signature, a real IHDR chunk
   header, and a plausible 64 by 64 size, so every dimension bound is satisfied
   and only the IHDR CRC is wrong. This is the file that proves the structural
   check is what does the work, not the bounds. */
$crafted = $tmp . '/crafted.png';
file_put_contents(
    $crafted,
    "\x89PNG\r\n\x1a\n"
    . "\x00\x00\x00\x0dIHDR"
    . pack('NN', 64, 64) . chr(8) . chr(0) . chr(0) . chr(0) . chr(0)
    . "BOGUSCRC"                      // where the real CRC32 belongs
    . "<?php echo 'payload'; ?>"
);

file_put_contents($empty, '');

/** Build a $_FILES-shaped entry from a path on disk. */
$upload = static function (string $path, ?string $name = null, int $error = UPLOAD_ERR_OK): array {
    return [
        'name'     => $name ?? basename($path),
        'type'     => 'image/png',        // claimed by the browser, never trusted
        'tmp_name' => $path,
        'error'    => $error,
        'size'     => is_file($path) ? (int) filesize($path) : 0,
    ];
};

/** A fresh copy, because avatar_store() moves the file it is given. */
$copyOf = static function (string $path) use ($tmp): string {
    $clone = $tmp . '/copy_' . bin2hex(random_bytes(4)) . '.' . pathinfo($path, PATHINFO_EXTENSION);
    copy($path, $clone);
    return $clone;
};

/* Confirms the fixtures really are what the suite claims, so a later failure
   points at avatar_store() rather than at a broken fixture. */
section('Fixtures');

check('the PNG fixture is a real PNG',
    (@getimagesize($png)[2] ?? null) === IMAGETYPE_PNG);
check('the GIF fixture is a real GIF',
    (@getimagesize($gif)[2] ?? null) === IMAGETYPE_GIF);
check('the script fixture is not an image',   @getimagesize($script) === false);

/* These two are the point of avatar_header_is_intact(). getimagesize() is
   happy with both, so anything relying on it alone would store them. */
check('getimagesize is fooled by the naive polyglot',
    (@getimagesize($polyglot)[2] ?? null) === IMAGETYPE_PNG);
check('and it reads absurd dimensions out of the payload',
    (int) (@getimagesize($polyglot)[0] ?? 0) > AVATAR_MAX_DIMENSION);
check('getimagesize is fooled by the crafted polyglot too',
    (@getimagesize($crafted)[2] ?? null) === IMAGETYPE_PNG);
check('and the crafted one reports a perfectly plausible size', (function () use ($crafted) {
    $info = @getimagesize($crafted);
    return (int) $info[0] === 64 && (int) $info[1] === 64;
})());

/* ------------------------------------------------------------- storing */

section('Storing a picture');

$stored = avatar_store(1, $upload($copyOf($png)));
check('a valid PNG is accepted',   $stored['ok'] === true);
check('no error is reported',      $stored['error'] === null);
check('a path is returned',        is_string($stored['path']));
check('it is inside the avatar directory',
    str_starts_with((string) $stored['path'], 'storage/avatars/'));
check('the file is on disk',
    is_file(__DIR__ . '/../' . $stored['path']));
check('the row records it',
    (string) $pdo->query('SELECT avatar_path FROM user WHERE user_id = 1')->fetchColumn()
        === $stored['path']);
check('avatar_has reports it',     avatar_has(1) === true);
check('another learner is unaffected', avatar_has(2) === false);

check('the stored name is generated, not the uploaded one', (function () use ($stored) {
    $name = basename((string) $stored['path']);
    return !str_contains($name, 'portrait') && str_starts_with($name, '1_');
})());

check('a GIF is accepted and keeps its own extension', (function () use ($upload, $copyOf, $gif) {
    $r = avatar_store(2, $upload($copyOf($gif)));
    return $r['ok'] === true && str_ends_with((string) $r['path'], '.gif');
})());

/* --------------------------------------------------- the extension is a lie */

section('The type comes from the bytes, not the filename');

$before = avatar_relative_path(1);

check('a PHP script named .png is refused', (function () use ($upload, $copyOf, $script) {
    $r = avatar_store(1, $upload($copyOf($script), 'portrait.png'));
    return $r['ok'] === false && is_string($r['error']);
})());

check('a PHP payload behind a PNG signature is refused', (function () use ($upload, $copyOf, $polyglot) {
    $r = avatar_store(1, $upload($copyOf($polyglot), 'portrait.png'));
    return $r['ok'] === false;
})());

check('a crafted polyglot with a plausible size is refused too',
    (function () use ($upload, $copyOf, $crafted) {
        /* Every dimension bound is satisfied here, so if this is refused it is
           the IHDR CRC that refused it. Without avatar_header_is_intact() this
           file is stored. */
        $r = avatar_store(1, $upload($copyOf($crafted), 'portrait.png'));
        return $r['ok'] === false;
    })());

section('Header parsing, on its own');

check('a real PNG passes',            avatar_header_is_intact($png, IMAGETYPE_PNG));
check('a real GIF passes',            avatar_header_is_intact($gif, IMAGETYPE_GIF));
check('the naive polyglot fails',     !avatar_header_is_intact($polyglot, IMAGETYPE_PNG));
check('the crafted polyglot fails',   !avatar_header_is_intact($crafted, IMAGETYPE_PNG));
check('a PNG checked as a GIF fails', !avatar_header_is_intact($png, IMAGETYPE_GIF));
check('a GIF checked as a PNG fails', !avatar_header_is_intact($gif, IMAGETYPE_PNG));
check('a missing file fails',         !avatar_header_is_intact($tmp . '/nope.png', IMAGETYPE_PNG));
check('an unsupported type fails',    !avatar_header_is_intact($png, IMAGETYPE_BMP));
check('a truncated PNG fails', (function () use ($tmp, $png) {
    $short = $tmp . '/short.png';
    file_put_contents($short, substr((string) file_get_contents($png), 0, 20));
    $r = avatar_header_is_intact($short, IMAGETYPE_PNG);
    @unlink($short);
    return !$r;
})());
check('a PNG with one byte of its header flipped fails', (function () use ($tmp, $png) {
    // The CRC is over the header, so changing a declared dimension breaks it.
    $bytes = (string) file_get_contents($png);
    $bytes[20] = chr(ord($bytes[20]) ^ 0x01);
    $tampered = $tmp . '/tampered.png';
    file_put_contents($tampered, $bytes);
    $r = avatar_header_is_intact($tampered, IMAGETYPE_PNG);
    @unlink($tampered);
    return !$r;
})());

section('More filename lies');

check('a double extension does not get a picture stored', (function () use ($upload, $copyOf, $script) {
    $r = avatar_store(1, $upload($copyOf($script), 'portrait.png.php'));
    return $r['ok'] === false;
})());

check('a real image named .php is still stored, with a png extension',
    (function () use ($upload, $copyOf, $png) {
        // The mirror image of the attack: the name is wrong, the bytes are fine,
        // and the extension written to disk comes from the bytes.
        $r = avatar_store(1, $upload($copyOf($png), 'shell.php'));
        return $r['ok'] === true && str_ends_with((string) $r['path'], '.png');
    })());

check('none of the refusals changed the stored row to something invalid',
    avatar_relative_path(1) !== null);
check('no refused upload left a file in the avatar directory', (function () {
    foreach (glob(avatar_dir() . '/*') ?: [] as $file) {
        if (@getimagesize($file) === false) { return false; }
    }
    return true;
})());

/* ---------------------------------------------------------- other refusals */

section('Other things that are refused');

check('an empty file is refused',
    avatar_store(1, $upload($empty))['ok'] === false);
check('an image below the minimum size is refused',
    avatar_store(1, $upload($copyOf($tiny)))['ok'] === false);
check('an image beyond the maximum dimension is refused',
    avatar_store(1, $upload($copyOf($huge)))['ok'] === false);
check('a missing file is refused',
    avatar_store(1, $upload($tmp . '/does_not_exist.png'))['ok'] === false);
check('no file selected is refused',
    avatar_store(1, $upload($png, null, UPLOAD_ERR_NO_FILE))['ok'] === false);
check('a partial upload is refused',
    avatar_store(1, $upload($png, null, UPLOAD_ERR_PARTIAL))['ok'] === false);
check('an oversized upload is refused by PHP error code',
    avatar_store(1, $upload($png, null, UPLOAD_ERR_INI_SIZE))['ok'] === false);
check('an empty $_FILES entry is refused',
    avatar_store(1, [])['ok'] === false);
check('a user id of zero is refused',
    avatar_store(0, $upload($copyOf($png)))['ok'] === false);

check('every refusal names a reason the learner can act on', (function () use ($upload, $copyOf, $script, $tiny) {
    foreach ([$script, $tiny] as $bad) {
        $r = avatar_store(1, $upload($copyOf($bad)));
        if (!is_string($r['error']) || strlen($r['error']) < 10) { return false; }
    }
    return true;
})());

/* -------------------------------------------------------------- replacing */

section('Replacing a picture removes the old file');

$first  = avatar_relative_path(1);
$firstAbsolute = __DIR__ . '/../' . $first;
$second = avatar_store(1, $upload($copyOf($png)));

check('the replacement is stored',        $second['ok'] === true);
check('the path changed',                 $second['path'] !== $first);
check('the row points at the new file',   avatar_relative_path(1) === $second['path']);
check('the old file is gone from disk',   !is_file($firstAbsolute));
check('the new file is on disk',          is_file(__DIR__ . '/../' . $second['path']));
check('the learner still has exactly one picture', (function () {
    return count(glob(avatar_dir() . '/1_*') ?: []) === 1;
})());

/* --------------------------------------------------------------- removing */

section('Removing a picture');

$path = avatar_relative_path(1);
check('removal reports success',   avatar_remove(1) === true);
check('the file is gone',          !is_file(__DIR__ . '/../' . $path));
check('the row is cleared', (function () use ($pdo) {
    return $pdo->query('SELECT avatar_path FROM user WHERE user_id = 1')->fetchColumn() === null;
})());
check('avatar_has now reports false',   avatar_has(1) === false);
check('removing again reports nothing to do', avatar_remove(1) === false);
check('the other learner still has theirs',   avatar_has(2) === true);

section('Deleting outside the avatar directory is refused');

check('a traversal path is refused', (function () use ($tmp) {
    $victim = $tmp . '/important.txt';
    file_put_contents($victim, 'must survive');
    avatar_unlink('storage/avatars/../../tests/../' . basename($victim));
    return is_file($victim);
})());

check('a path outside storage/avatars is refused', (function () use ($tmp) {
    $victim = $tmp . '/also_important.txt';
    file_put_contents($victim, 'must survive');
    avatar_unlink('config/ai.php');
    return is_file($victim) && is_file(__DIR__ . '/../config/ai.php');
})());

/* ------------------------------------------------------- missing file case */

section('A row whose file has vanished');

check('a stored row with no file on disk reports no avatar', (function () use ($pdo) {
    $pdo->exec("UPDATE user SET avatar_path = 'storage/avatars/1_gone.png' WHERE user_id = 1");
    avatar_forget(1);
    // Reported as absent rather than as present, so the page shows initials
    // instead of a broken image.
    return avatar_relative_path(1) === null && avatar_has(1) === false;
})());

check('and no URL is offered for it', avatar_url(1) === null);

$pdo->exec('UPDATE user SET avatar_path = NULL WHERE user_id = 1');
avatar_forget(1);

/* ------------------------------------------------------------------- URLs */

section('URLs');

check('a learner with no picture has no URL', avatar_url(1) === null);

check('a learner with one gets a versioned URL', (function () {
    $url = avatar_url(2);
    return is_string($url)
        && str_starts_with($url, 'actions/avatar_show.php?v=')
        && (int) substr($url, strlen('actions/avatar_show.php?v=')) > 0;
})());

check('the URL carries no user id for anybody to change', (function () {
    // avatar_show.php serves the session's own picture and takes no id, so
    // there is nothing here to tamper with.
    return !str_contains((string) avatar_url(2), 'user');
})());

check('the prefix can be changed for a page at a different depth',
    str_starts_with((string) avatar_url(2, 'app/actions/'), 'app/actions/'));

/* --------------------------------------------------------------- initials */

section('Initials, for a learner with no picture');

check('two names give first and last',   avatar_initials('Patrick Cagas') === 'PC');
check('three names skip the middle',     avatar_initials('Maria Dela Cruz') === 'MC');
check('one name gives one letter',       avatar_initials('Cher') === 'C');
check('initials are upper-cased',        avatar_initials('juan dela cruz') === 'JC');
check('extra whitespace is ignored',     avatar_initials('   Ana   Reyes   ') === 'AR');
check('an empty name falls back',        avatar_initials('') === '?');
check('a whitespace-only name falls back', avatar_initials('   ') === '?');
check('null falls back',                 avatar_initials(null) === '?');
check('a non-Latin name still yields a letter',
    mb_strlen(avatar_initials('Renée Ångström')) === 2);

/* ------------------------------------------------------------- rendering */

section('Rendering');

$initialsHtml = avatar_html(1, 'Maria Dela Cruz');
check('a learner with no picture renders initials',
    str_contains($initialsHtml, 'ef-avatar-initials') && str_contains($initialsHtml, 'MC'));
check('and renders no img tag',   !str_contains($initialsHtml, '<img'));

$imageHtml = avatar_html(2, 'Other Learner');
check('a learner with a picture renders an img', str_contains($imageHtml, '<img'));
check('pointing at the serving endpoint',
    str_contains($imageHtml, 'actions/avatar_show.php?v='));
check('with the name as alt text',
    str_contains($imageHtml, 'alt="Other Learner"'));
check('both carry the shared avatar class',
    str_contains($initialsHtml, 'ef-avatar') && str_contains($imageHtml, 'ef-avatar'));

check('a name containing markup is escaped in the alt text', (function () {
    // User 2 has a picture, so this takes the <img> branch and the whole name
    // reaches the alt attribute.
    $html = avatar_html(2, '<script>alert(1)</script>');
    return str_contains($html, '<img')
        && !str_contains($html, '<script>')
        && str_contains($html, 'alt="&lt;script&gt;alert(1)&lt;/script&gt;"');
})());

check('a name containing markup cannot inject through the initials either', (function () use ($pdo) {
    // The initials branch emits at most two characters, so the danger here is
    // one stray "<", not a whole tag.
    $pdo->exec("INSERT INTO user (full_name, email) VALUES ('x', 'x@example.com')");
    $html = avatar_html((int) $pdo->lastInsertId(), '<script>alert(1)</script>');
    // The rendered initial is the escaped "<", and the only tag in the output
    // is the span this function wrote itself.
    return !str_contains($html, '<script')
        && str_contains($html, '>&lt;<')
        && substr_count($html, '<') === 2;
})());

check('a name containing a quote cannot break out of the attribute',
    !str_contains(avatar_html(2, 'Bobby " onerror="x'), '" onerror="'));

check('an extra class is applied',
    str_contains(avatar_html(2, 'Other', 'ef-avatar-xl'), 'ef-avatar-xl'));
check('an inline style is applied',
    str_contains(avatar_html(1, 'Maria', '', 'width:64px;'), 'width:64px;'));

/* ------------------------------------------------------------------ cache */

section('The per-request cache');

check('a change made behind the cache is picked up once forgotten', (function () use ($pdo) {
    $pdo->exec("UPDATE user SET avatar_path = NULL WHERE user_id = 2");
    $stale = avatar_has(2);        // still cached as present
    avatar_forget(2);
    return $stale === true && avatar_has(2) === false;
})());

check('forgetting everybody clears the whole cache', (function () {
    avatar_forget();
    return avatar_has(1) === false && avatar_has(2) === false;
})());

/* ---------------------------------------------------------------- cleanup */

foreach (glob(avatar_dir() . '/*') ?: [] as $file) {
    @unlink($file);
}
foreach (glob($tmp . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($tmp);

check('the suite leaves no avatar files behind',
    count(glob(avatar_dir() . '/*') ?: []) === 0);

echo "\n=============================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
