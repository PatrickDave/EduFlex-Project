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

/* -------------------------------------------------- the files must be there */

section('Files the application needs');

/* An incomplete FTP upload is the most likely reason for a 500 with an empty
 * body, which is what EduFlex gives when a require_once target is missing:
 * PHP fatals, and auth_boot() has already turned display_errors off, so
 * nothing reaches the browser at all. FileZilla records a failed transfer in
 * its log and then carries on, so a partial upload looks finished.
 *
 * DERIVED, not hand-written. The list this replaces named only the files
 * index.php reaches, so on 28 November 2026 it reported "15 boot files
 * present" on a server where includes/verification.php had never uploaded:
 * the landing page rendered perfectly and every page that signs somebody in
 * returned 500. A hand-maintained list of dependencies is a second copy of
 * the require statements, and it was wrong the first time one was added.
 *
 * So read every PHP file that IS here, pull out its literal __DIR__ requires
 * and check each target. A file that failed to upload is then named by
 * whichever present file requires it, which is exactly the fatal the browser
 * refuses to show. */
$scan = [];
foreach (['', 'includes', 'includes/vendor/phpmailer', 'partials',
          'app', 'app/actions', 'auth', 'config'] as $dir) {
    $scan = array_merge($scan, glob(rtrim("$root/$dir", '/') . '/*.php') ?: []);
}

/* TOKENISED, not matched with a regex. The regex this replaces read the
 * docblock at the top of partials/public_footer.php, which shows the include
 * line as a usage example, and reported that file as missing while it sat
 * right there. A check that cries wolf is worse than no check.
 *
 * token_get_all() hands back comments and strings as whole tokens, so an
 * example in a comment is skipped rather than parsed. Literal single-quoted
 * paths only: a computed path cannot be resolved without running the file,
 * and mail.php's fallback to mail.example.php is a computed one on purpose. */
$requireTokens = [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE];
$skipTokens    = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

$broken = [];
foreach ($scan as $file) {
    $src = @file_get_contents($file);
    if ($src === false) {
        continue;
    }
    $tokens = @token_get_all($src);
    $count  = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i])
            || !in_array($tokens[$i][0], $requireTokens, true)) {
            continue;
        }

        /* The three meaningful tokens after it must be __DIR__ . 'literal'.
         * Anything else is a computed path and is left alone. */
        $next = [];
        for ($j = $i + 1; $j < $count && count($next) < 3; $j++) {
            if (is_array($tokens[$j])
                && in_array($tokens[$j][0], $skipTokens, true)) {
                continue;
            }
            $next[] = $tokens[$j];
        }
        if (count($next) < 3
            || !is_array($next[0]) || $next[0][0] !== T_DIR
            || $next[1] !== '.'
            || !is_array($next[2]) || $next[2][0] !== T_CONSTANT_ENCAPSED_STRING
            || $next[2][1][0] !== "'") {
            continue;
        }

        $rel = stripslashes(substr($next[2][1], 1, -1));
        if (realpath(dirname($file) . $rel) !== false) {
            continue;
        }
        $from = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root) + 1));
        $broken[$from . '  needs  ' . $rel] = true;
    }
}

foreach (array_keys($broken) as $line) {
    result('FAIL', $line, 'MISSING: upload it');
}
result($broken === [] ? 'OK' : 'FAIL',
    sprintf('%d PHP files scanned', count($scan)),
    $broken === []
        ? 'every require resolves'
        : sprintf('%d unresolved require%s, listed above',
            count($broken), count($broken) === 1 ? '' : 's'));

/* A page can also be reached by URL rather than by require, and the scan above
 * is blind to those: nothing in the tree requires auth/logout.php, the sidebar
 * just links to it. On 28 November 2026 the whole auth/ directory, one file,
 * had not uploaded. Signing in worked, so the symptom was Sign Out answering
 * 404 and the learner stuck in a session they could not end.
 *
 * So take the second route in as well: every href and action in the source
 * that names a .php file. Skipped are the ones that cannot be checked without
 * running the page: a value containing <?, an absolute URL, and an anchor or
 * scheme.
 *
 * Resolved against SEVERAL bases, not just the directory of the file holding
 * it. A partial is included from somewhere else, so partials/topbar.php's
 * href="materials.php" means app/materials.php and never partials/. Resolving
 * from the holding directory alone reported eleven files as missing while
 * every one of them was present. A link is counted as broken only when it
 * resolves from none of the bases, which is what an absent file looks like. */
$links = [];
foreach ($scan as $file) {
    $src = @file_get_contents($file);
    if ($src === false) {
        continue;
    }
    preg_match_all(
        '/(?:href|action)\s*=\s*(["\'])([^"\'<>]+?\.php)(?:\?[^"\']*)?\1/i',
        $src,
        $found
    );
    foreach ($found[2] as $href) {
        if (str_contains($href, '<?') || str_contains($href, '://')
            || preg_match('#^(?:mailto:|tel:|/|\#)#', $href)) {
            continue;
        }
        $resolved = false;
        foreach ([dirname($file), $root, "$root/app", "$root/app/actions"] as $base) {
            if (realpath("$base/$href") !== false) {
                $resolved = true;
                break;
            }
        }
        if ($resolved) {
            continue;
        }
        $from = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root) + 1));
        $links[$from . '  links to  ' . $href] = true;
    }
}

foreach (array_keys($links) as $line) {
    result('FAIL', $line, 'MISSING: upload it');
}
result($links === [] ? 'OK' : 'FAIL', 'pages reached by link, not by require',
    $links === []
        ? 'every link resolves'
        : sprintf('%d broken link%s, listed above',
            count($links), count($links) === 1 ? '' : 's'));

/* Nothing requires these in PHP, so the scan above cannot see them. The video
 * is a WARN rather than a FAIL: it is the one file over InfinityFree's 10 MB
 * cap, and every page still works without it. */
$assets = [
    'assets/css/eduflex.css',
    'assets/js/eduflex.js',
    'assets/vendor/bootstrap-5.3.3.min.css',
    'assets/vendor/jquery-3.7.1.min.js',
    'assets/video/eduflex-explainer.mp4',
];
$assetFail = 0;
foreach ($assets as $rel) {
    if (is_file("$root/$rel")) {
        continue;
    }
    if (str_ends_with($rel, '.mp4')) {
        result('WARN', $rel, 'only the explainer; every page still works');
        continue;
    }
    result('FAIL', $rel, 'MISSING: upload it');
    $assetFail++;
}
result($assetFail === 0 ? 'OK' : 'FAIL', 'stylesheets and scripts present',
    $assetFail === 0 ? 'nothing missing' : "$assetFail missing, see above");

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

/* HTTPS to the provider YOU are configured for, not a fixed one. A host can
   allow one API host and block another, so testing Google's endpoint while
   AI_DRIVER is openai-compatible would prove nothing about the call that
   actually runs.

   Only the host is contacted, never a completion, so this costs no quota and
   needs no key. Any HTTP status proves the connection was made. */
$providerHost = 'https://generativelanguage.googleapis.com/';
if (defined('AI_DRIVER') && AI_DRIVER === 'openai-compatible' && defined('AI_BASE_URL')) {
    $parts = parse_url((string) AI_BASE_URL);
    if (!empty($parts['host'])) {
        $providerHost = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . '/';
    }
}
if (defined('AI_DRIVER') && AI_DRIVER === 'mock') {
    result('OK', 'HTTPS out to the provider host', 'not needed, driver is mock');
} elseif (extension_loaded('curl')) {
    $ch = curl_init($providerHost);
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
    $host = parse_url($providerHost, PHP_URL_HOST) ?: $providerHost;
    result($code > 0 ? 'OK' : 'FAIL', "HTTPS out to $host",
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
