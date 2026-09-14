<?php
/**
 * EduFlex — small shared helpers.
 */

declare(strict_types=1);

/**
 * Escape a value for HTML output.
 *
 * Use this on EVERY piece of data that comes from the database or the user.
 * Forgetting it is how cross-site scripting gets in.
 */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Send a redirect and stop. Always exit after a Location header, otherwise
 * the rest of the page still executes.
 */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/* -------------------------------------------------------------------------
   Flash messages: survive exactly one redirect, then clear themselves.
   ------------------------------------------------------------------------- */

function flash_set(string $key, mixed $value): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['_flash'][$key] = $value;
}

function flash_get(string $key, mixed $default = null): mixed
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SESSION['_flash'][$key])) {
        return $default;
    }
    $value = $_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);
    return $value;
}

/* -------------------------------------------------------------------------
   CSRF protection

   Every state-changing form must carry a token. Without it, another site can
   make a logged-in learner submit your forms without knowing.
   ------------------------------------------------------------------------- */

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

/**
 * Hidden input to drop inside every <form method="post">.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/**
 * Verify the submitted token. hash_equals avoids timing comparison leaks.
 */
function csrf_check(?string $submitted): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    return is_string($submitted)
        && !empty($_SESSION['_csrf'])
        && hash_equals($_SESSION['_csrf'], $submitted);
}

/* -------------------------------------------------------------------------
   Mastery model

   This is the server-side half of the model described in README section 4.
   The thresholds MUST match assets/js/eduflex.js. If they drift, the screen
   and the database will disagree.
   ------------------------------------------------------------------------- */

const MASTERY_MIN_ITEMS   = 5;
const MASTERY_MASTERED_AT = 85.0;
const MASTERY_DEVELOPING_AT = 70.0;

/**
 * Bloom weights used by the mastery formula.
 *
 * A correct answer at Create is worth more than one at Remember, because it
 * demonstrates more. These values MUST match the `bloom_level` table in
 * database/01_schema.sql; that table exists so the weights can be cited in
 * Chapter III without reading code, and this array so the calculation does not
 * need a database round trip per item.
 */
const BLOOM_WEIGHTS = [
    'Remember'   => 1.0,
    'Understand' => 1.2,
    'Apply'      => 1.5,
    'Analyze'    => 1.8,
    'Evaluate'   => 2.0,
    'Create'     => 2.2,
];

/**
 * How quickly an older answer stops counting: 1 / (1 + 0.15k), where k is how
 * many answers ago it was. The most recent answer has full weight; the tenth
 * most recent has 0.4.
 */
const MASTERY_RECENCY_DECAY = 0.15;

/**
 * Classify a mastery score into a band.
 *
 * @return string one of: mastered, developing, weak, none
 */
function mastery_band(?float $score, int $scoredItems): string
{
    if ($scoredItems < MASTERY_MIN_ITEMS || $score === null) {
        return 'none';
    }
    if ($score >= MASTERY_MASTERED_AT) {
        return 'mastered';
    }
    if ($score >= MASTERY_DEVELOPING_AT) {
        return 'developing';
    }
    return 'weak';
}

function mastery_band_label(string $band): string
{
    return [
        'mastered'   => 'Mastered',
        'developing' => 'Developing',
        'weak'       => 'Weak',
        'none'       => 'No data',
    ][$band] ?? 'No data';
}

/**
 * Render a mastery row in the shape assets/js/eduflex.js expects.
 * Pass null for $score when the topic is under the item minimum.
 */
function mastery_row_html(string $topic, ?float $score, int $scoredItems): string
{
    return sprintf(
        '<div class="ef-mastery-row" data-topic="%s" data-mastery="%s" data-items="%d"></div>',
        e($topic),
        $score === null ? '' : e((string) round($score)),
        $scoredItems
    );
}
