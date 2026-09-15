<?php
/**
 * EduFlex — Privacy Policy and Terms of Service test suite.
 *
 *     php tests/legal_test.php
 *
 * No database, no network, no server.
 *
 * Two things are worth asserting about a consent document, and they pull in
 * opposite directions:
 *
 *   A placeholder must never be mistaken for an answer. The registration form
 *   asks a participant to agree to these pages, so a page that quietly shows
 *   "TODO: adviser name" where an adviser should be is worse than no page.
 *
 *   The warnings must actually disappear once the values are supplied, or the
 *   project team will paper over them by hand and the mechanism stops meaning
 *   anything.
 *
 * The suite also reads privacy.php and terms.php as text, to check the claims
 * that a panel would check: that they are public, that they are linked from
 * the registration form, and that the promises they make about export and
 * delete match functions that exist.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/legal.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "  PASS  $label\n"; }
    else            { $failed++; echo "  FAIL  $label\n"; }
}
function section(string $name): void { echo "\n$name\n"; }

echo "EduFlex legal pages suite\n=========================\n";

/**
 * A file with its comments removed.
 *
 * Needed because these files talk ABOUT the things being checked for.
 * privacy.php explains in a docblock that it must not sit behind
 * auth_require_login(), and login.php carries a comment recording that a link
 * used to be a dead one. A plain substring search finds those and reports a
 * problem that is not there, so the comments come out first. token_get_all()
 * is PHP's own lexer, so this is exact rather than a regex that half works.
 */
function source_without_comments(string $path): string
{
    $out = '';
    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (!is_array($token)) {
            $out .= $token;
            continue;
        }
        if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
            continue;
        }
        // Markup between PHP blocks can carry HTML comments of its own.
        $out .= $token[0] === T_INLINE_HTML
            ? (string) preg_replace('#<!--.*?-->#s', '', $token[1])
            : $token[1];
    }
    return $out;
}

/** Every value supplied, as the project team will eventually leave it. */
$complete = [
    'contact email'   => 'eduflex@example.edu',
    'lead researcher' => 'A Researcher, BSIT',
    'adviser'         => 'An Adviser, Capstone Adviser',
    'retention'       => 'Deleted within one month of the final defense.',
    'ethics'          => 'CCS-2026-014',
];

/* ------------------------------------------------- recognising a placeholder */

section('Recognising an unfilled value');

check('an empty string is a placeholder',      legal_is_placeholder(''));
check('whitespace only is a placeholder',      legal_is_placeholder("   \n\t "));
check('a TODO is a placeholder',               legal_is_placeholder('TODO: adviser name'));
check('lower case todo is caught',             legal_is_placeholder('todo: fill this in'));
check('mixed case ToDo is caught',             legal_is_placeholder('ToDo'));
check('a TODO left mid-sentence is caught',
    legal_is_placeholder('Prof. Somebody, TODO check the title'));

check('a real email is not a placeholder',     !legal_is_placeholder('eduflex@example.edu'));
check('a real name is not a placeholder',      !legal_is_placeholder('A Researcher, BSIT'));
check('a real sentence is not a placeholder',
    !legal_is_placeholder('Deleted within one month of the final defense.'));

/* ---------------------------------------------------- the shipped values */

section('The values as they ship');

/* These were placeholders until Patrick supplied them on 15 September 2026.
   The assertions are now the other way round, and they earn their place as a
   regression guard: if anybody reverts one of the five to a TODO, or empties
   it while editing, the suite fails instead of the consent documents quietly
   going back to showing a red banner to participants. */

check('no shipped value is still a placeholder', legal_missing() === []);
check('so the documents report themselves as complete', legal_is_incomplete() === false);
check('and no banner is rendered',            legal_notice() === '');
check('the effective date is set',            !legal_is_placeholder(LEGAL_EFFECTIVE));

check('every one of the five has real content', (function () {
    foreach (legal_values() as $value) {
        // Long enough to be an answer rather than a stub like "n/a" or "yes".
        if (mb_strlen(trim((string) $value)) < 8) { return false; }
    }
    return true;
})());

check('the contact value is a usable email address',
    filter_var(LEGAL_CONTACT_EMAIL, FILTER_VALIDATE_EMAIL) !== false);

check('the retention line says what happens, not just when',
    mb_strlen(LEGAL_RETENTION) > 30);

/* --------------------------------------------------- once they are filled in */

section('Once the project team fills them in');

check('nothing is reported missing',       legal_missing($complete) === []);
check('the documents are complete',        legal_is_incomplete($complete) === false);
check('and the banner disappears entirely', legal_notice($complete) === '');

check('one remaining placeholder still raises the banner', (function () use ($complete) {
    $almost = $complete;
    $almost['adviser'] = 'TODO: adviser name and title';
    return legal_notice($almost) !== ''
        && legal_missing($almost) === ['adviser'];
})());

check('the banner reads correctly for a single missing value', (function () use ($complete) {
    $almost = $complete;
    $almost['ethics'] = '';
    $notice = legal_notice($almost);
    // "The ethics still need..." rather than "The  and ethics still need..."
    return str_contains($notice, 'The ethics still need')
        && !str_contains($notice, ' and ethics');
})());

check('the banner lists two missing values with an "and"', (function () use ($complete) {
    $almost = $complete;
    $almost['ethics']   = '';
    $almost['adviser']  = '';
    return str_contains(legal_notice($almost), 'adviser and ethics');
})());

/* -------------------------------------------------------------- rendering */

section('Rendering a value');

check('a placeholder is wrapped in a visible marker',
    str_contains(legal_value('TODO: adviser name'), 'ef-legal-missing'));
check('a real value is not marked',
    !str_contains(legal_value('A Researcher'), 'ef-legal-missing'));
check('a real value is returned as text', legal_value('A Researcher') === 'A Researcher');

check('a value containing markup is escaped', (function () {
    $out = legal_value('<script>alert(1)</script>');
    return !str_contains($out, '<script>') && str_contains($out, '&lt;script&gt;');
})());
check('an ampersand in a name is escaped',
    str_contains(legal_value('Reyes & Santos'), '&amp;'));

/* ------------------------------------------------------ who to contact */

section('The Who to Contact block');

$contact = legal_contact_block();

check('every researcher is listed', (function () use ($contact) {
    foreach (LEGAL_TEAM as $person) {
        if (!str_contains($contact, e($person['name']))) { return false; }
    }
    return true;
})());

check('four researchers are listed',   count(LEGAL_TEAM) === 4);
check('the lead researcher is first',  LEGAL_TEAM[0]['role'] === 'Lead researcher');
check('every entry has a phone number', (function () {
    foreach (LEGAL_TEAM as $person) {
        if (trim($person['phone']) === '') { return false; }
    }
    return true;
})());

check('the institutional number appears',
    str_contains($contact, LEGAL_INSTITUTION_PHONE));
check('the adviser is named',
    str_contains($contact, e(LEGAL_ADVISER)));
check('the technical panel paragraph is present',
    str_contains($contact, 'technical panel'));

/* Patrick asked on 15 September 2026 that the adviser's personal mobile not be
   published, and that it is his to give rather than the team's. This is the
   check that would catch somebody pasting the whole consent form back in. */
check('the adviser has NO personal mobile number anywhere', (function () use ($contact) {
    if (str_contains($contact, '962 217 6215')) { return false; }
    foreach (LEGAL_TEAM as $person) {
        if (str_contains(strtolower($person['name']), 'barral')) { return false; }
    }
    return true;
})());

check('the adviser is reachable through the college instead',
    str_contains($contact, 'College of Computer Studies'));

section('Phone numbers are dialable');

check('a number becomes a tel: link',
    str_contains(legal_phone_link('+63 960 358 3431'), 'href="tel:+639603583431"'));
check('the spacing is kept in the visible text',
    str_contains(legal_phone_link('+63 960 358 3431'), '>+63 960 358 3431<'));
check('a landline with spaces is stripped for the href',
    str_contains(legal_phone_link('032 255 7777'), 'href="tel:0322557777"'));
check('punctuation is stripped from the href but kept in the text', (function () {
    // The consent form writes this number both ways. Either spelling has to
    // dial the same digits, while the visible text stays as written.
    $out = legal_phone_link('032 -255 - 7777');
    return str_contains($out, 'href="tel:0322557777"')
        && str_contains($out, '>032 -255 - 7777<');
})());

check('a crafted number cannot break out of the attribute', (function () {
    $out = legal_phone_link('+63 900" onclick="alert(1)');
    return !str_contains($out, 'onclick="alert(1)"');
})());

section('The block appears in both places');

check('the compact form drops the lead paragraph',
    !str_contains(legal_contact_block(true), 'You can ask the research team'));
check('but keeps every researcher', (function () {
    $compact = legal_contact_block(true);
    foreach (LEGAL_TEAM as $person) {
        if (!str_contains($compact, e($person['name']))) { return false; }
    }
    return true;
})());

check('privacy.php renders the block',
    str_contains((string) file_get_contents(__DIR__ . '/../privacy.php'), 'legal_contact_block('));
check('the Support page renders the block',
    str_contains((string) file_get_contents(__DIR__ . '/../app/support.php'), 'legal_contact_block('));
check('the Support page includes the file it needs',
    str_contains((string) file_get_contents(__DIR__ . '/../app/support.php'), 'includes/legal.php'));

/* app/*.php is generated. If the block is in the page but not in the template,
   the next build_pages.py run silently deletes it. */
check('the Support page block comes from build_pages.py, not a hand edit',
    str_contains((string) file_get_contents(__DIR__ . '/../build_pages.py'), 'legal_contact_block('));

/* ------------------------------------------------------- the AI disclosure */

section('What the privacy page says about the AI provider');

$ai = legal_ai_disclosure();
check('a driver is always reported',   is_string($ai['driver']) && $ai['driver'] !== '');
check('sends is a boolean',            is_bool($ai['sends']));
check('the mock is reported as sending nothing',
    $ai['driver'] !== 'mock' || $ai['sends'] === false);
check('a real driver is reported as sending',
    $ai['driver'] === 'mock' || $ai['sends'] === true);

/* ------------------------------------------------ the documents themselves */

section('The pages on disk');

$privacy = (string) file_get_contents(__DIR__ . '/../privacy.php');
$terms   = (string) file_get_contents(__DIR__ . '/../terms.php');

check('privacy.php exists and is not empty', strlen($privacy) > 2000);
check('terms.php exists and is not empty',   strlen($terms) > 2000);

/* A prospective participant must be able to read both BEFORE registering, so
   neither may be gated. This is the check that would catch somebody "tidying
   up" by adding the usual guard. Comments are stripped first: both files
   explain in a docblock why the guard is absent. */
check('privacy.php does not require a login',
    !str_contains(source_without_comments(__DIR__ . '/../privacy.php'), 'auth_require_login'));
check('terms.php does not require a login',
    !str_contains(source_without_comments(__DIR__ . '/../terms.php'), 'auth_require_login'));

check('both send security headers', str_contains($privacy, 'security_headers()')
    && str_contains($terms, 'security_headers()'));

check('each links to the other',
    str_contains($privacy, 'terms.php') && str_contains($terms, 'privacy.php'));

/* The promises these pages make have to match functions that exist. */
$auth = (string) file_get_contents(__DIR__ . '/../includes/auth.php');
check('the export promised on the privacy page is implemented',
    str_contains($privacy, 'Export my data') && str_contains($auth, 'function auth_export_data'));
check('the deletion promised on the privacy page is implemented',
    str_contains($privacy, 'Delete my account') && str_contains($auth, 'function auth_delete_account'));
check('the right to withdraw is stated on both',
    str_contains($privacy, 'ithdraw') && str_contains($terms, 'ithdraw'));

check('the privacy page states that the team can read the database',
    str_contains($privacy, 'project team'));
check('the terms state that generated output can be wrong',
    str_contains($terms, 'confidently wrong'));
check('the terms state that data may be lost',
    str_contains($terms, 'could be lost'));
check('the terms state that nothing reaches official grades',
    str_contains($terms, 'grade'));

/* ------------------------------------------------------------- the links */

section('Every public page links to both');

foreach (['index.php', 'login.php', 'register.php'] as $page) {
    $html = (string) file_get_contents(__DIR__ . '/../' . $page);
    $viaFooter = str_contains($html, 'public_footer.php');
    $direct    = str_contains($html, 'privacy.php') && str_contains($html, 'terms.php');
    check($page . ' reaches both, directly or through the shared footer',
        $viaFooter || $direct);
    check($page . ' has no dead href="#" link left',
        !str_contains(source_without_comments(__DIR__ . '/../' . $page), 'href="#"'));
}

$footer = (string) file_get_contents(__DIR__ . '/../partials/public_footer.php');
check('the shared footer links to the privacy policy', str_contains($footer, 'href="privacy.php"'));
check('the shared footer links to the terms',          str_contains($footer, 'href="terms.php"'));

$register = (string) file_get_contents(__DIR__ . '/../register.php');
check('the consent checkbox links to the real terms',
    str_contains($register, 'href="terms.php"'));
check('the consent checkbox links to the real privacy policy',
    str_contains($register, 'href="privacy.php"'));
check('the consent links open in a new tab, so the form is not lost',
    str_contains($register, 'target="_blank"'));
check('and carry rel=noopener',
    str_contains($register, 'rel="noopener"'));

echo "\n=========================\n";
echo "Passed: $passed   Failed: $failed\n";
exit($failed === 0 ? 0 : 1);
