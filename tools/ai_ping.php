<?php
/**
 * EduFlex — prove the API key works, for the price of one request.
 *
 *     php tools/ai_ping.php
 *     php tools/ai_ping.php --json     also exercise the strict-JSON path
 *
 * The live HTTP call is the one path in EduFlex that no test suite can cover:
 * all 1118 checks run against the mock, by design, so they need no key, no
 * network and no quota. That leaves exactly one thing unproven, and it is the
 * thing most likely to be wrong the first time: whether the key, the base URL
 * and the model name in config/ai.php actually agree with each other.
 *
 * This makes ONE call with a trivial prompt and a tiny token cap, so finding out
 * costs a single request instead of the twelve that tools/rubric_run.php spends.
 * Run it first. If this fails, the rubric run would have failed too, later and
 * more expensively.
 *
 * It never prints the key. It prints the key's LENGTH, which is enough to catch
 * the two most common mistakes, a blank paste and a trailing newline, without
 * putting a secret on a terminal that may be screen-shared during a defense.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("This script runs from the command line only.\n");
}

require_once __DIR__ . '/../includes/ai.php';

$options  = getopt('', ['json', 'help']);
$wantJson = isset($options['json']);

if (isset($options['help'])) {
    echo <<<TEXT

    Check that config/ai.php can really reach your provider.

        php tools/ai_ping.php            one plain-text call
        php tools/ai_ping.php --json     one call asking for strict JSON

    Costs one request. Prints the driver, model and latency, and the reply.
    Never prints the API key.


    TEXT;
    exit(0);
}

$driver  = defined('AI_DRIVER') ? AI_DRIVER : '(undefined)';
$model   = defined('AI_MODEL') ? AI_MODEL : '(undefined)';
$baseUrl = defined('AI_BASE_URL') ? AI_BASE_URL : '(undefined)';
$key     = defined('AI_API_KEY') ? AI_API_KEY : '';

printf("\nEduFlex provider check\n======================\n\n");
printf("  driver    %s\n", $driver);
printf("  model     %s\n", $model);
printf("  base URL  %s\n", $driver === 'gemini' ? '(not used by this driver)' : $baseUrl);
printf("  API key   %s\n\n", $key === ''
    ? 'EMPTY'
    : sprintf('set, %d characters (not shown)', strlen($key)));

/* The mock answers instantly and always succeeds, so pinging it proves only
   that PHP runs. Say so rather than printing a green tick that means nothing. */
if ($driver === 'mock') {
    fwrite(STDERR, <<<TEXT
    This is the mock driver. It never makes a network call, so there is nothing
    here to check: it would report success on a machine with no internet and no
    key at all.

    Set AI_DRIVER and AI_API_KEY in config/ai.php, then run this again.


    TEXT);
    exit(2);
}

if (trim($key) === '') {
    fwrite(STDERR, "  No API key is set. Add AI_API_KEY to config/ai.php.\n\n");
    exit(2);
}

/* A key with surrounding whitespace is a real and very confusing failure: it is
   invisible in an editor and the provider rejects it as malformed rather than
   as wrong, so the error message does not point at the cause. */
if ($key !== trim($key)) {
    printf("  WARNING: the key has leading or trailing whitespace. That is almost\n");
    printf("           certainly a copy-paste artefact and will be rejected.\n\n");
}

printf("Sending one request...\n\n");

$provider = ai_provider();

$result = $wantJson
    ? $provider->complete(
        'You return strict JSON and nothing else.',
        'Return exactly {"ok": true} and nothing more.',
        true,
        40
      )
    : $provider->complete(
        'You are a terse assistant. Answer in under ten words.',
        'Reply with exactly: EduFlex connection confirmed.',
        false,
        40
      );

if ($result->ok) {
    printf("  SUCCESS\n\n");
    printf("  model reported  %s\n", $result->model !== '' ? $result->model : '(not reported)');
    printf("  latency         %d ms\n", $result->latencyMs);
    printf("  attempts        %d%s\n", $result->attempts,
        $result->attempts > 1 ? '  (it retried, so the provider is throttling)' : '');
    printf("  reply           %s\n", trim($result->text) !== ''
        ? '"' . trim(preg_replace('/\s+/', ' ', $result->text) ?? '') . '"'
        : '(empty)');
    if ($wantJson) {
        printf("  decoded JSON    %s\n",
            $result->data === null ? 'FAILED TO DECODE' : json_encode($result->data));
    }
    printf("\n  config/ai.php is working. The live HTTP path is now proven.\n");
    printf("  Next: upload a document, run topic detection, and read the topics\n");
    printf("  before spending twelve calls on tools/rubric_run.php.\n\n");
    exit(0);
}

printf("  FAILED\n\n");
printf("  latency   %d ms\n", $result->latencyMs);
printf("  attempts  %d\n", $result->attempts);
printf("  error     %s\n\n", (string) $result->error);

/* The four failures that actually happen, and what each one means. Without this
   the provider's own wording sends you looking in the wrong place: a wrong
   model name and a wrong key produce errors that read almost identically. */
$error = strtolower((string) $result->error);

/* 404 is checked FIRST. The driver's wording for it, "The provider rejected the
   request (404)", contains the word "rejected", so a looser key check placed
   above this one swallows it and prints billing advice for what is actually a
   dead model name. That happened on 12 October 2026 and cost real time. */
if (str_contains($error, '404')) {
    printf("  The model name was not recognised. AI_MODEL is '%s'.\n\n", $model);
    printf("  Model names are retired faster than you would expect. Ask the\n");
    printf("  provider which ones this key can actually use:\n\n");
    if ($driver === 'gemini') {
        printf("    https://generativelanguage.googleapis.com/v1beta/models\n");
        printf("    (send your key in an x-goog-api-key header)\n\n");
    } else {
        printf("    GET %s/models  with your key as a Bearer token\n\n", rtrim($baseUrl, '/'));
    }
    printf("  Note that a model can be listed there and still be retired, so\n");
    printf("  confirm with a real call like this one.\n\n");
} elseif (str_contains($error, 'key') || str_contains($error, '401')) {
    printf("  The key was refused. Check, in this order:\n");
    printf("    1. the key is pasted whole, with no space or newline at either end\n");
    printf("    2. it belongs to the provider AI_BASE_URL points at\n");
    printf("    3. the account behind it has credit; OpenAI has no free API tier,\n");
    printf("       so a brand-new account with no top-up is refused this way\n\n");
} elseif (str_contains($error, 'no credit')) {
    printf("  Your key is VALID. It authenticated, or this would have been a 401.\n");
    printf("  The account behind it simply has no credit.\n\n");
    printf("  OpenAI has no free API tier. A new account must be topped up before\n");
    printf("  the first request, at:\n");
    printf("      https://platform.openai.com/settings/organization/billing\n\n");
    printf("  The smallest top-up covers this whole study many times over. From\n");
    printf("  EduFlex's own constants the entire project is roughly 700 to 900\n");
    printf("  requests: 12 per document, 1 per practice set, 1 per chat question.\n\n");
    printf("  Waiting will not help. This is billing, not throttling.\n\n");
} elseif (str_contains($error, 'rate') || str_contains($error, '429')) {
    printf("  Rate limited. EduFlex already retried %d times with increasing waits.\n",
        $result->attempts);
    printf("  A new account starts in the lowest tier. Wait a minute and run it again.\n\n");
} elseif (str_contains($error, 'model') || str_contains($error, '404')) {
    printf("  The model name was not recognised. AI_MODEL is '%s'.\n", $model);
    printf("  Model names change; check the current list in your provider's docs.\n\n");
} elseif (str_contains($error, 'reach') || str_contains($error, 'resolve')
       || str_contains($error, 'timed out') || str_contains($error, 'curl')) {
    printf("  The provider could not be reached. No internet, a firewall, or a\n");
    printf("  wrong AI_BASE_URL. This is also what you would see in a room with\n");
    printf("  no wifi, which is worth remembering before the defense.\n\n");
}

exit(1);
