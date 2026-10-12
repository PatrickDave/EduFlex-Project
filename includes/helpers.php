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
/**
 * A URL for one of our own stylesheets or scripts, with a cache-busting token.
 *
 * Added 12 October 2026 after a real failure. The password reveal shipped
 * working and tested, and on Patrick's browser the icon sat in the wrong place
 * and did nothing when clicked. Nothing was wrong with the code. The markup is
 * rendered by PHP so it appeared immediately, but eduflex.css and eduflex.js
 * were served from his cache: the old stylesheet had no rule to position the
 * button, and the old script had no handler to run. Apache sends no
 * Cache-Control for these files, so the browser was free to reuse them without
 * asking, and it did.
 *
 * That failure mode is nasty because it looks like broken code to the person
 * reporting it and like working code to the person who wrote it. The fix is for
 * the URL to change whenever the file does.
 *
 * filemtime rather than a hand-maintained version number, because a number
 * somebody has to remember to bump is a number that does not get bumped. Pass
 * the path exactly as the page would write it, "../" and all; the leading hops
 * are stripped only to find the file on disk.
 */
function asset(string $href): string
{
    $relative = ltrim((string) preg_replace('#^(?:\.\./)+#', '', $href), '/');
    $full     = __DIR__ . '/../' . $relative;

    // A missing file is not worth an exception on a page render. It will 404
    // visibly on its own, which is a clearer signal than a white screen.
    $version = is_file($full) ? (string) filemtime($full) : '0';

    return e($href) . '?v=' . $version;
}

/**
 * The show/hide control that sits inside a password field.
 *
 * One function for the same reason ef_nav_icon() is one function: the icon is
 * inline SVG, it appears three times across login and registration, and three
 * pasted copies drift. Rule 3 and the CSP rule out an icon font or a sprite
 * from a CDN, so inline is the only option and duplication is the real risk.
 *
 * A cat's eye, as asked for: the almond outline with a vertical slit pupil
 * instead of the round one every other site draws.
 *
 * type="button" is load-bearing. A <button> inside a <form> submits it by
 * default, so without this, revealing your password would post the login form.
 *
 * The <svg> elements are aria-hidden and the accessible name lives on the
 * button, so a screen reader announces one control rather than two drawings.
 * JavaScript swaps the label between "Show" and "Hide"; the markup ships in the
 * hidden state because that is what the field starts as.
 */
function password_toggle_html(string $inputId): string
{
    $id = e($inputId);

    $eye = static fn(string $class, bool $slashed): string =>
        '<svg class="' . $class . '" width="19" height="19" viewBox="0 0 24 24"'
        . ' fill="none" aria-hidden="true">'
        // The almond. Pointed at both ends, which is what makes it read as a cat.
        . '<path d="M1.8 12S5.6 5 12 5s10.2 7 10.2 7-3.8 7-10.2 7S1.8 12 1.8 12Z"'
        . ' stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>'
        // The slit.
        . '<ellipse class="ef-eye-pupil" cx="12" cy="12" rx="1.9" ry="4.2"'
        . ' fill="currentColor"/>'
        . ($slashed
            ? '<path d="M3.5 3.5l17 17" stroke="currentColor" stroke-width="1.6"'
              . ' stroke-linecap="round"/>'
            : '')
        . '</svg>';

    return '<button type="button" class="ef-password-toggle" data-password-toggle'
         . ' aria-controls="' . $id . '" aria-pressed="false"'
         . ' aria-label="Show password" title="Show password">'
         . $eye('ef-eye-open', false)
         . $eye('ef-eye-shut', true)
         . '</button>';
}

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
