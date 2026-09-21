<?php
/**
 * EduFlex — landing page content test suite.
 *
 *     php tests/manuscript_test.php
 *
 * No database, no network, no server.
 *
 * Guards three things about the public landing page:
 *
 *   Every nav link resolves. Features, Methodology, How it Works and Resources
 *   were dead for a long time because nothing checked them. A nav item that
 *   goes nowhere is the most visible defect a panel member can find.
 *
 *   The module list matches the manuscript. Chapter III decomposes EduFlex into
 *   seven modules and 34 sub-modules, and the page is built from that list.
 *
 *   Nothing is advertised that is not built. "Manage Subscription" and
 *   "Generate Mock Examinations" were in the manuscript and not in the code
 *   until 16 September 2026, and were kept off this page until they worked.
 *   Now that both exist, each claim has to be backed by the file and the screen
 *   that provide it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/manuscript.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "  PASS  $label\n"; }
    else            { $failed++; echo "  FAIL  $label\n"; }
}
function section(string $name): void { echo "\n$name\n"; }

echo "EduFlex landing content suite\n=============================\n";

$index = (string) file_get_contents(__DIR__ . '/../index.php');

/* ------------------------------------------------------------ the nav */

section('Every nav link resolves');

/* The four items in the landing page nav. Each must have an anchor on the page
   AND a link pointing at it; either alone is a broken nav. */
foreach (['features', 'methodology', 'how', 'resources'] as $id) {
    check('#' . $id . ' has a link',   str_contains($index, 'href="#' . $id . '"'));
    check('#' . $id . ' has a target', str_contains($index, 'id="' . $id . '"'));
}

check('no nav item still points at href="#"', (function () use ($index) {
    // Comments are stripped, because index.php records in a comment that a link
    // used to be dead.
    $src = '';
    foreach (token_get_all($index) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) { continue; }
            $src .= $token[0] === T_INLINE_HTML
                ? (string) preg_replace('#<!--.*?-->#s', '', $token[1])
                : $token[1];
            continue;
        }
        $src .= $token;
    }
    return !str_contains($src, 'href="#"');
})());

/* -------------------------------------------------------------- modules */

section('The module list matches Chapter III');

check('there are seven modules', count(MANUSCRIPT_MODULES) === 7);

check('the seven are named as the manuscript names them', (function () {
    $expected = [
        'Account and Access Management',
        'Learning Resource Management',
        'AI Learning Companion',
        'Learning Activity and Assessment',
        'Adaptive Learning and Recommendations',
        'Progress and Engagement',
        'Support and Feedback',
    ];
    return array_column(MANUSCRIPT_MODULES, 'title') === $expected;
})());

check('every module has a blurb and at least four functions', (function () {
    foreach (MANUSCRIPT_MODULES as $m) {
        if (trim($m['blurb']) === '' || count($m['items']) < 4) { return false; }
    }
    return true;
})());

check('all 34 sub-modules are shown', (function () {
    $n = 0;
    foreach (MANUSCRIPT_MODULES as $m) { $n += count($m['items']); }
    return $n === 34;
})());

check('no sub-module is listed twice', (function () {
    $all = [];
    foreach (MANUSCRIPT_MODULES as $m) {
        foreach ($m['items'] as $item) { $all[] = strtolower($item); }
    }
    return count($all) === count(array_unique($all));
})());

section('Nothing is claimed that is not built');

/* These two were listed in the manuscript and missing from the code until
   16 September 2026, and were kept off this page until they worked. The
   assertions now run the other way: each is advertised, and each has to be
   backed by something real. Listing a sub-module here before it exists is the
   failure mode this section guards. */

check('"Manage subscription" is advertised', (function () {
    foreach (MANUSCRIPT_MODULES as $m) {
        foreach ($m['items'] as $item) {
            if (stripos($item, 'subscription') !== false) { return true; }
        }
    }
    return false;
})());

check('and it is backed by a real module',
    is_file(__DIR__ . '/../includes/subscription.php'));

check('and the Settings page renders it',
    str_contains((string) file_get_contents(__DIR__ . '/../app/settings.php'),
        'subscription_for('));

check('"Generate mock examinations" is advertised', (function () {
    foreach (MANUSCRIPT_MODULES as $m) {
        foreach ($m['items'] as $item) {
            if (stripos($item, 'mock exam') !== false) { return true; }
        }
    }
    return false;
})());

check('and the code really does store a second activity type', (function () {
    // The reason mock examinations can be advertised. If this ever reverts to
    // practice_set alone, the claim on the page became false.
    $exams = (string) file_get_contents(__DIR__ . '/../includes/exams.php');
    return str_contains($exams, "EXAM_ACTIVITY_TYPE = 'mock_exam'");
})());

check('and the Practice page offers one',
    str_contains((string) file_get_contents(__DIR__ . '/../app/practice.php'),
        'actions/exam_start.php'));

/* --------------------------------------------------------- the explainer */

/* The video is the one asset on this page a visitor notices is broken before
   anything else, and most of the ways it breaks are silent: a file that is not
   there, a dead caption reference, a switch to a YouTube embed that would
   breach CLAUDE.md rule 3, or a lightbox that keeps playing audio after it is
   closed.

   Structural checks run against the markup with comments stripped. index.php
   explains this design in a PHP comment that mentions <video> and <track> in
   prose, and a naive substring count would read those as elements. */

$markup = (function () use ($index) {
    $out = '';
    foreach (token_get_all($index) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) { continue; }
            $out .= $token[0] === T_INLINE_HTML
                ? (string) preg_replace('#<!--.*?-->#s', '', $token[1])
                : $token[1];
            continue;
        }
        $out .= $token;
    }
    return $out;
})();

$how = (string) (strstr($markup, 'id="how"') ?: '');
$how = (string) (substr($how, 0, (int) strpos($how, '</section>')) ?: '');

section('The explainer opens in a dialog');

check('the How it Works section offers a poster to click',
    str_contains($how, 'class="ef-video-trigger"')
    && str_contains($how, 'data-video-open'));

check('that poster is a real button, not a div with a handler',
    (bool) preg_match('/<button[^>]*class="ef-video-trigger"/', $how));

check('it names what it does for a screen reader',
    (bool) preg_match('/<button[^>]*aria-label="Play the EduFlex explainer[^"]*"/', $how));

check('the section itself holds no video element any more',
    !str_contains($how, '<video'));

check('there is exactly one video element on the whole page',
    substr_count($markup, '<video') === 1);

check('and it lives inside the dialog', (function () use ($markup) {
    $start = strpos($markup, '<dialog');
    $end   = strpos($markup, '</dialog>');
    $video = strpos($markup, '<video');
    return $start !== false && $end !== false && $video !== false
        && $video > $start && $video < $end;
})());

check('the dialog is addressable by the script',
    str_contains($markup, 'data-video-dialog'));

check('it has a close control',
    str_contains($markup, 'data-video-close'));

check('the hero button opens it, and still scrolls without JavaScript', (function () use ($markup) {
    // A button that does nothing when a script fails to load is worse than a
    // link that does less, so the href is the fallback and must survive.
    return (bool) preg_match('/<a href="#how"[^>]*data-video-open/', $markup);
})());

section('The video itself');

check('it is served from this origin, not embedded from elsewhere',
    str_contains($markup, 'src="assets/video/eduflex-explainer.mp4"'));

check('the file it points at is really there',
    is_file(__DIR__ . '/../assets/video/eduflex-explainer.mp4'));

check('no third-party player was introduced anywhere on the page', (function () use ($index) {
    // Rule 3, and the CSP would block it. An <iframe> to youtube-nocookie.com
    // is the tempting shortcut this guards against.
    foreach (['<iframe', 'youtube.com', 'youtu.be', 'youtube-nocookie', 'vimeo.com', 'player.vimeo'] as $needle) {
        if (stripos($index, $needle) !== false) { return false; }
    }
    return true;
})());

check('nothing is downloaded until somebody asks to watch',
    str_contains($markup, 'preload="none"'));

check('it carries controls, so a visitor can pause it',
    (bool) preg_match('/<video[^>]*\bcontrols\b/s', $markup));

check('it does not autoplay from the markup',
    !str_contains($markup, 'autoplay'));

check('it plays inline rather than hijacking the screen on a phone',
    (bool) preg_match('/<video[^>]*\bplaysinline\b/s', $markup));

check('there is a fallback for a browser that cannot play it',
    str_contains($markup, 'Download it instead'));

section('The poster');

check('a poster is declared on the video',
    str_contains($markup, 'poster="assets/video/eduflex-explainer-poster.jpg"'));

check('and the trigger shows the same image',
    str_contains($how, 'src="assets/video/eduflex-explainer-poster.jpg"'));

check('the poster file is really there',
    is_file(__DIR__ . '/../assets/video/eduflex-explainer-poster.jpg'));

check('it is a real JPEG and the same 16/9 shape as the video', (function () {
    $p = __DIR__ . '/../assets/video/eduflex-explainer-poster.jpg';
    $size = @getimagesize($p);
    // Rule 14 applies to UPLOADS, where the file is hostile. This one is built
    // by the project and committed, so the check here is only that it is the
    // right shape, not that it is safe.
    return is_array($size)
        && $size[2] === IMAGETYPE_JPEG
        && abs(($size[0] / $size[1]) - (16 / 9)) < 0.01;
})());

check('the decorative poster image is not announced twice', (function () use ($how) {
    // The button already carries the label. An alt on the image inside it would
    // be read out on top of that.
    return (bool) preg_match('/<img[^>]*alt=""/', $how);
})());

section('Closing the dialog stops the audio');

$js = (string) file_get_contents(__DIR__ . '/../assets/js/eduflex.js');

check('the behaviour is wired up at load',
    str_contains($js, 'function initVideoDialog()')
    && str_contains($js, 'initVideoDialog();'));

check('closing pauses the video', (function () use ($js) {
    // A closed <dialog> is only hidden. Without this the narration carries on
    // from an element nobody can see, which is the classic lightbox bug.
    $on = (string) (strstr($js, "dialog.addEventListener('close'") ?: '');
    return str_contains(substr($on, 0, 400), 'video.pause()');
})());

check('and rewinds it, so the next open starts at the beginning', (function () use ($js) {
    $on = (string) (strstr($js, "dialog.addEventListener('close'") ?: '');
    return str_contains(substr($on, 0, 400), 'currentTime = 0');
})());

check('the hero anchor does not also jump the page',
    str_contains($js, 'event.preventDefault()'));

check('the scroll lock is added and removed in equal measure', (function () use ($js) {
    // Added on open, removed on close. One without the other leaves the page
    // permanently unscrollable, which is a worse bug than the one it fixes.
    return substr_count($js, "classList.add('ef-no-scroll')") === 1
        && substr_count($js, "classList.remove('ef-no-scroll')") === 1;
})());

check('a browser without <dialog> is left alone rather than broken',
    str_contains($js, "typeof dialog.showModal !== 'function'"));

section('The captions');

/* 21 September 2026. The .srt supplied with the video is from the previous cut:
   it opens on the title card where v2 opens on "The Challenge", and its last
   cue ends at 3:37 against the file's 3:18. It was converted to .vtt and wired
   in before those frames were actually looked at, and then taken out again.

   Captions that drift from the audio are worse than no captions, because a
   viewer relying on them has no way to tell. So the rule is not "there must be
   a track", it is "if there is a track, the file behind it must exist". That
   way adding a corrected .vtt later is a one-line change that this suite will
   check rather than a change that quietly ships a dead reference. */

check('no caption track points at a file that is not there', (function () use ($markup) {
    if (!preg_match_all('/<track\b[^>]*\bsrc="([^"]+)"/i', $markup, $m)) {
        return true;                      // no track at all: nothing to break
    }
    foreach ($m[1] as $src) {
        if (!is_file(__DIR__ . '/../' . $src)) { return false; }
    }
    return true;
})());

check('the stale v1 caption file is not being served',
    !is_file(__DIR__ . '/../assets/video/eduflex-explainer.vtt'));

check('Apache is still told that .vtt is text/vtt, for when a matching one lands',
    (function () {
        // XAMPP's mime.types does not list vtt, so without this a track would be
        // served as application/octet-stream and the browser would refuse it.
        $ht = (string) @file_get_contents(__DIR__ . '/../assets/video/.htaccess');
        return str_contains($ht, 'AddType text/vtt .vtt');
    })());

section('The player is styled');

$css = (string) file_get_contents(__DIR__ . '/../assets/css/eduflex.css');

check('every class the markup uses is defined', (function () use ($css) {
    foreach ([
        '.ef-video-figure', '.ef-video-trigger', '.ef-video-play',
        '.ef-video-duration', '.ef-video-caption', '.ef-video-dialog',
        '.ef-video-dialog-panel', '.ef-video-close', '.ef-no-scroll',
    ] as $sel) {
        if (!str_contains($css, $sel)) { return false; }
    }
    return true;
})());

check('the trigger and the panel both hold 16/9, the shape of the file',
    substr_count($css, 'aspect-ratio: 16 / 9') >= 2);

check('the dialog only lays itself out when it is open', (function () use ($css) {
    // Setting display on .ef-video-dialog unconditionally overrides the UA rule
    // dialog:not([open]) { display: none } and leaves the video on the page.
    return str_contains($css, '.ef-video-dialog[open]')
        && !preg_match('/\.ef-video-dialog\s*\{[^}]*\bdisplay:\s*flex/s', $css);
})());

check('a visitor who asked for reduced motion gets no movement from it', (function () use ($css) {
    $block = (string) (strstr($css, '@media (prefers-reduced-motion: reduce)') ?: '');
    return str_contains($block, '.ef-video-trigger')
        && str_contains($block, '.ef-video-dialog-panel');
})());

check('the play badge keeps its centring when transforms are switched off', (function () use ($css) {
    // .ef-video-play is centred WITH a transform, so a blanket
    // "transform: none !important" would throw it into the corner.
    $block = (string) (strstr($css, '@media (prefers-reduced-motion: reduce)') ?: '');
    return str_contains($block, '.ef-video-play { transform: translate(-50%, -50%) !important; }');
})());

/* ----------------------------------------------------------- references */

section('References');

check('there are references at all', count(MANUSCRIPT_REFERENCES) > 20);
check('there are 26 after deduplication', count(MANUSCRIPT_REFERENCES) === 26);

check('none is listed twice', (function () {
    // The manuscript lists 28 entries, two of them duplicated. Those are
    // collapsed here; this fails if they creep back.
    $keys = array_map(
        static fn($r) => substr(strtolower((string) preg_replace('/\s+/', ' ', $r)), 0, 70),
        MANUSCRIPT_REFERENCES
    );
    return count($keys) === count(array_unique($keys));
})());

check('every entry carries a year', (function () {
    foreach (MANUSCRIPT_REFERENCES as $r) {
        if (!preg_match('/\((?:n\.d\.|\d{4})[a-z]?\)/', $r)) { return false; }
    }
    return true;
})());

check('every entry is a real sentence, not a stub', (function () {
    foreach (MANUSCRIPT_REFERENCES as $r) {
        if (mb_strlen(trim($r)) < 40) { return false; }
    }
    return true;
})());

check('accented characters survived the extraction', (function () {
    $all = implode(' ', MANUSCRIPT_REFERENCES);
    // Marín, Händel, Millán, Merriënboer, Küchemann, Fernández.
    return str_contains($all, 'Marín')
        && str_contains($all, 'Händel')
        && str_contains($all, 'Küchemann')
        && mb_check_encoding($all, 'UTF-8');
})());

check('the Bloom taxonomy source is cited, since the weights rest on it',
    str_contains(implode(' ', MANUSCRIPT_REFERENCES), 'Krathwohl'));

section('Rendering a reference');

check('a plain reference is escaped', (function () {
    $out = manuscript_reference_html('Smith, J. (2020). <script>alert(1)</script>.');
    return !str_contains($out, '<script>') && str_contains($out, '&lt;script&gt;');
})());

check('a DOI becomes a link', (function () {
    $out = manuscript_reference_html('Author, A. (2024). Title. https://doi.org/10.3390/x');
    return str_contains($out, '<a href="https://doi.org/10.3390/x"');
})());

check('external links open safely', (function () {
    $out = manuscript_reference_html('A. (2024). T. https://example.org/paper');
    return str_contains($out, 'rel="noopener noreferrer"')
        && str_contains($out, 'target="_blank"');
})());

check('a reference with no URL gets no link',
    !str_contains(manuscript_reference_html('Knowles, M. S. (1975). Self-Directed Learning.'), '<a '));

check('a URL cannot smuggle an attribute out of the href', (function () {
    $out = manuscript_reference_html('A (2024). https://x.test/a" onmouseover="alert(1)');
    return !str_contains($out, 'onmouseover="alert(1)"');
})());

check('four entries carry a link', (function () {
    $n = 0;
    foreach (MANUSCRIPT_REFERENCES as $r) {
        if (str_contains(manuscript_reference_html($r), '<a href=')) { $n++; }
    }
    return $n === 4;
})());

/* -------------------------------------------------------- the page itself */

section('The page renders the content');

check('index.php includes the content file',
    str_contains($index, "includes/manuscript.php"));
check('it loops the modules',    str_contains($index, 'MANUSCRIPT_MODULES'));
check('it loops the references', str_contains($index, 'MANUSCRIPT_REFERENCES'));

check('the mastery formula is shown, and matches helpers.php', (function () use ($index) {
    // The landing page states the recency decay; helpers.php is where it is
    // actually applied. If one changes, the other is now wrong.
    return str_contains($index, '0.15k')
        && abs(MASTERY_RECENCY_DECAY - 0.15) < 0.0001;
})());

check('the Bloom weights on the page match BLOOM_WEIGHTS', (function () use ($index) {
    foreach (BLOOM_WEIGHTS as $level => $weight) {
        // Each level and its weight appear in the table.
        if (!str_contains($index, '<td>' . $level . '</td>')) { return false; }
        if (!str_contains($index, '<td>' . number_format($weight, 1) . '</td>')) { return false; }
    }
    return true;
})());

check('the bands on the page match the mastery thresholds', (function () use ($index) {
    return str_contains($index, (string) (int) MASTERY_MASTERED_AT . '% and above')
        && str_contains($index, (string) (int) MASTERY_DEVELOPING_AT . '% to 84%')
        && str_contains($index, 'Under ' . MASTERY_MIN_ITEMS . ' scored answers');
})());

echo "\n=============================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
