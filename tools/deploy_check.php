<?php
/**
 * EduFlex — is this machine able to serve the system?
 *
 *     php tools/deploy_check.php          from the command line
 *
 * Or copy it to the web root and open it in a browser on the server, which is
 * the only option on shared hosting with no shell. DELETE IT AFTERWARDS: it
 * reports which config files exist, and that is a map nobody outside the team
 * needs.
 *
 * It never prints a password, a key, or the contents of any config file. It
 * reports only whether things are present and reachable.
 *
 * Checks are ordered the way a deployment fails: PHP first, then extensions,
 * then config, then the database, then the two outbound paths that shared
 * hosts block and that EduFlex genuinely needs.
 */

declare(strict_types=1);

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    /* Over the web this needs ?run=1, so a crawler, a prefetch or a guessed
     * path does not print the deployment's shape to a stranger. It is a speed
     * bump and not a lock: the real protection is tools/.htaccess, and the
     * real answer is deleting this file once it has told you what you need. */
    if (($_GET['run'] ?? '') !== '1') {
        http_response_code(404);
        exit("Not found.\n");
    }
}

$root = dirname(__DIR__);
$pass = 0;
$warn = 0;
$fail = 0;

function result(string $state, string $label, string $detail = ''): void
{
    global $pass, $warn, $fail;
    if ($state === 'OK')   { $pass++; }
    if ($state === 'WARN') { $warn++; }
    if ($state === 'FAIL') { $fail++; }
    printf("  %-5s %-44s %s\n", $state, $label, $detail);
}

function section(string $name): void
{
    echo "\n$name\n";
}

echo "EduFlex deployment check\n========================\n";

/* ------------------------------------------------------------------ PHP */

section('PHP');

result(version_compare(PHP_VERSION, '8.0', '>=') ? 'OK' : 'FAIL',
    'PHP 8.0 or newer', PHP_VERSION);

foreach (['pdo_mysql' => 'FAIL', 'curl' => 'FAIL', 'mbstring' => 'FAIL',
          'openssl' => 'FAIL', 'zip' => 'WARN', 'gd' => 'WARN'] as $ext => $severity) {
    $has = extension_loaded($ext);
    $note = '';
    if (!$has && $ext === 'zip') { $note = 'DOCX upload will fail at runtime'; }
    if (!$has && $ext === 'gd')  { $note = 'only needed if image resizing is added'; }
    if (!$has && $ext === 'curl') { $note = 'the AI provider cannot be called'; }
    result($has ? 'OK' : $severity, "extension: $ext", $note);
}

$uploadMax = ini_get('upload_max_filesize');
$postMax   = ini_get('post_max_size');
result('OK', 'upload_max_filesize / post_max_size', "$uploadMax / $postMax");

/* --------------------------------------------------------------- config */

section('Configuration');

foreach ([
    'config/ai.php'             => 'the API key. Copy from config/ai.example.php',
    'config/mail.php'           => 'SMTP. Copy from config/mail.example.php',
] as $file => $hint) {
    result(is_file("$root/$file") ? 'OK' : 'FAIL', $file,
        is_file("$root/$file") ? 'present' : "MISSING: $hint");
}

$hasLocalDb = is_file("$root/config/database.local.php");
result($hasLocalDb ? 'OK' : 'WARN', 'config/database.local.php',
    $hasLocalDb
        ? 'present, overrides the defaults'
        : 'absent, using the XAMPP defaults (right on a laptop, wrong on a server)');

if (is_file("$root/config/ai.php")) {
    require_once "$root/config/ai.php";
    $driver = defined('AI_DRIVER') ? AI_DRIVER : '(not set)';
    // Never print the key. Its length is enough to tell empty from filled.
    $keyLen = defined('AI_API_KEY') ? strlen((string) AI_API_KEY) : 0;
    result('OK', 'AI_DRIVER', (string) $driver);
    result($driver === 'mock' || $keyLen > 0 ? 'OK' : 'WARN',
        'AI_API_KEY', $driver === 'mock'
            ? 'not needed, driver is mock'
            : ($keyLen > 0 ? "set ($keyLen characters)" : 'EMPTY'));
}

/* -------------------------------------------------------------- storage */

section('Writable storage');

foreach (['storage/uploads', 'storage/avatars'] as $dir) {
    $path = "$root/$dir";
    if (!is_dir($path)) {
        result('WARN', $dir, 'does not exist yet; it is created on first use');
        continue;
    }
    result(is_writable($path) ? 'OK' : 'FAIL', $dir,
        is_writable($path) ? 'writable' : 'NOT WRITABLE: chmod it to 755');
}

result(is_file("$root/storage/.htaccess") ? 'OK' : 'FAIL', 'storage/.htaccess',
    is_file("$root/storage/.htaccess")
        ? 'present, uploads are not served as code'
        : 'MISSING: an uploaded .php could be executed. Upload it.');

/* ------------------------------------------------------------- database */

section('Database');

try {
    require_once "$root/config/database.php";
    $pdo = db();
    $pdo->query('SELECT 1');
    result('OK', 'connection', DB_USER . '@' . DB_HOST . ' / ' . DB_NAME);

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    result(count($tables) > 0 ? 'OK' : 'FAIL', 'schema imported',
        count($tables) . ' tables');

    foreach (['user', 'learning_resource', 'support_reply'] as $t) {
        result(in_array($t, $tables, true) ? 'OK' : 'FAIL', "table: $t",
            in_array($t, $tables, true) ? '' : 'run tools/migrate.php --apply');
    }
} catch (Throwable $e) {
    // The message can name the host, never a password.
    result('FAIL', 'connection', 'cannot connect; check config/database.local.php');
}

/* ------------------------------------------------- the two outbound paths */

section('Outbound network (what free hosts block)');

/* HTTPS to the provider. Tested against Google's generic endpoint rather than
   a model call, so it costs no quota and needs no key. A host that blocks
   outbound HTTPS fails here and the companion would fail at runtime. */
if (extension_loaded('curl')) {
    $ch = curl_init('https://generativelanguage.googleapis.com/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_NOBODY         => true,
    ]);
    curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // Any HTTP status proves the connection was made. 403/404 is fine here.
    result($code > 0 ? 'OK' : 'FAIL', 'HTTPS out to the provider host',
        $code > 0 ? "reachable (HTTP $code)" : "BLOCKED: $err");
} else {
    result('FAIL', 'HTTPS out to the provider host', 'curl extension missing');
}

/* SMTP on 587. Opened and closed without sending or authenticating, so it
   proves only that the port is not blocked, which is the thing shared hosts
   actually do. Port 25 is blocked almost everywhere and 465 is inconsistent;
   EduFlex uses 587 on purpose. */
$smtpHost = 'smtp.gmail.com';
if (is_file("$root/config/mail.php")) {
    $mailCfg = require "$root/config/mail.php";
    if (is_array($mailCfg) && !empty($mailCfg['host'])) {
        $smtpHost = (string) $mailCfg['host'];
    }
}
$sock = @fsockopen($smtpHost, 587, $errNo, $errStr, 12);
if ($sock) {
    $greeting = trim((string) fgets($sock, 256));
    fclose($sock);
    result('OK', "SMTP out to $smtpHost:587", $greeting !== '' ? '220 received' : 'connected');
} else {
    result('FAIL', "SMTP out to $smtpHost:587",
        "BLOCKED: $errStr. Email verification will not work.");
}

/* ----------------------------------------------------------------- https */

section('HTTPS');

if ($cli) {
    result('WARN', 'served over HTTPS', 'cannot tell from the command line');
} else {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    result($secure ? 'OK' : 'WARN', 'served over HTTPS',
        $secure
            ? 'Secure cookies and HSTS switch on by themselves'
            : 'turn on SSL in the control panel, then revisit over https');
}

/* ---------------------------------------------------------------- totals */

printf("\n========================\n");
printf("OK: %d   WARN: %d   FAIL: %d\n\n", $pass, $warn, $fail);

if ($fail > 0) {
    echo "Fix every FAIL before showing this to anybody. See DEPLOY.md.\n\n";
}
if (!$cli) {
    echo "DELETE THIS FILE from the server now that you have read it.\n\n";
}
exit($fail === 0 ? 0 : 1);
