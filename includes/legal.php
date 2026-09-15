<?php
/**
 * EduFlex — the facts behind privacy.php and terms.php.
 *
 * PATRICK: this file is the only one you need to edit. Fill in the five
 * constants below and the warning banners on both pages disappear by
 * themselves. Nothing else has to change.
 *
 * Why it works this way. The two documents describe what EduFlex actually
 * does with a learner's data, and that part was written from the code: what
 * the schema stores, what leaves the server, what export and delete really
 * remove. Those statements are checkable against `includes/` and
 * `database/01_schema.sql`, and they should stay true as the system changes.
 *
 * The five values below are the opposite: facts about your study that cannot
 * be read out of the code. An adviser's name, an ethics approval reference and
 * a retention period are commitments to a participant. Inventing them would
 * put words in your mouth and in your adviser's, and a participant could rely
 * on them. So they start as placeholders, and every page that shows one also
 * shows a banner saying it is unfinished.
 *
 * Both documents are written as a participant information sheet as much as a
 * policy, because that is what they are for: Chapter III promises informed
 * consent and the right to withdraw, and these are where that promise is made
 * in words a participant reads before they register.
 */

declare(strict_types=1);

/* -------------------------------------------------------------------------
   THE FIVE THINGS ONLY YOU CAN FILL IN
   ------------------------------------------------------------------------- */

/**
 * Where a participant writes with a question about their data.
 *
 * Supplied by Patrick on 15 September 2026. Worth one thought before the
 * defense: this is a personal address rather than an institutional one, and
 * some ethics reviewers expect a university address on a participant-facing
 * document. It works either way; swap it if your adviser prefers.
 */
const LEGAL_CONTACT_EMAIL = 'patrickcagas123@gmail.com';

/**
 * The researcher accountable for the data. Name only: both pages supply the
 * role around it ("Lead researcher: ..." on privacy.php, "The researcher
 * accountable for this system is ..." on terms.php), so a role in the value
 * itself reads as a stutter.
 */
const LEGAL_RESEARCHER = 'Patrick Dave Cagas';

/** The faculty adviser supervising the study. */
const LEGAL_ADVISER = 'Christian C. Barral, MIT';

/**
 * How long participant data is kept after the study ends, and what happens to
 * it then. Your ethics submission should already state this.
 *
 * Chosen by Patrick on 15 September 2026. Check it matches what your ethics
 * submission says: if the two disagree, the submission is the one a reviewer
 * will hold you to.
 */
const LEGAL_RETENTION = 'All participant data is deleted at the end of the academic year in '
                      . 'which the study concludes. Nothing is kept after that.';

/**
 * The ethics clearance.
 *
 * Taken from the UCREC Protocol Approval (Form 2.7) supplied on 15 September
 * 2026. For the record, since the certificate is not in this repository:
 *
 *   Protocol      BSIT(1)-2026-08-040
 *   Study         EduFlex: AI-Powered Personalized Learning Companion
 *   Investigator  Patrick Dave F. Cagas, University of Cebu Main Campus
 *   Letter dated  24 August 2026
 *   Valid         24 April 2026 to 24 April 2027, as printed
 *   Chair         Dr. Juanito N. Zuasula, Jr., MD
 *
 * The expiry is the operative fact for a participant, so that is what the page
 * shows. Keep this constant in step with the certificate: if a continuing
 * review extends the approval, the new expiry belongs here.
 */
const LEGAL_ETHICS = 'Approved by the University of Cebu Research Ethics Committee (UCREC), '
                   . 'protocol BSIT(1)-2026-08-040, valid until 24 April 2027.';

/** Shown as the effective date on both documents. */
const LEGAL_EFFECTIVE = '15 September 2026';

/* -------------------------------------------------------------------------
   Who to contact

   The "Who to Contact" block from the approved Informed Consent Form. UCREC
   expects a participant to be able to reach a named human before the study
   starts and at any point afterwards, so this is a requirement of the
   clearance rather than a nicety.

   Held here as data rather than written into each page, because it appears in
   two places now (privacy.php and app/support.php) and a phone number that is
   right in one of them and stale in the other is worse than no number.

   THE ADVISER'S MOBILE IS DELIBERATELY ABSENT. Patrick asked on 15 September
   2026 that the four student researchers be listed with their numbers and the
   adviser not be. He is named below and reachable through the college line,
   which is a published institutional number. Do not add a personal number for
   him without asking him first.
   ------------------------------------------------------------------------- */

/**
 * The student researchers, in the order the consent form lists them.
 *
 * @var list<array{name:string, role:string, phone:string}>
 */
const LEGAL_TEAM = [
    ['name' => 'Patrick Dave Cagas',          'role' => 'Lead researcher', 'phone' => '+63 960 358 3431'],
    ['name' => 'Christian Exequeil Alminaza', 'role' => 'Researcher',      'phone' => '+63 966 365 2341'],
    ['name' => 'Gabriel Andrew Belandres',    'role' => 'Researcher',      'phone' => '+63 919 340 9242'],
    ['name' => 'Trishley Rosalita',           'role' => 'Researcher',      'phone' => '+63 952 628 9598'],
];

/** The published switchboard for both the college and the university. */
const LEGAL_INSTITUTION_PHONE = '032 255 7777';

/* -------------------------------------------------------------------------
   Helpers
   ------------------------------------------------------------------------- */

/**
 * Every value above, so a page can check them all at once.
 *
 * @return array<string,string>
 */
function legal_values(): array
{
    return [
        'contact email'   => LEGAL_CONTACT_EMAIL,
        'lead researcher' => LEGAL_RESEARCHER,
        'adviser'         => LEGAL_ADVISER,
        'retention'       => LEGAL_RETENTION,
        'ethics'          => LEGAL_ETHICS,
    ];
}

/**
 * Is this value still a placeholder?
 *
 * Deliberately loose. Somebody half-filling a field and leaving "TODO" in the
 * middle of it should still trip the warning.
 */
function legal_is_placeholder(string $value): bool
{
    $value = trim($value);
    return $value === '' || stripos($value, 'todo') !== false;
}

/**
 * The labels of everything still unfinished.
 *
 * $values is injectable so tests can check the finished state too. The
 * constants above cannot be reassigned at runtime, so without this there would
 * be no way to assert that the warnings actually go away.
 *
 * @param array<string,string>|null $values defaults to the real constants
 * @return list<string>
 */
function legal_missing(?array $values = null): array
{
    $missing = [];
    foreach ($values ?? legal_values() as $label => $value) {
        if (legal_is_placeholder((string) $value)) {
            $missing[] = (string) $label;
        }
    }
    return $missing;
}

/**
 * True while either document still contains something unfinished.
 *
 * @param array<string,string>|null $values
 */
function legal_is_incomplete(?array $values = null): bool
{
    return legal_missing($values) !== [];
}

/**
 * Render one value, or a visible marker when it is still a placeholder.
 *
 * The marker is deliberately ugly. A participant should never quietly read
 * "TODO: adviser name" as though it were an answer, and you should not be able
 * to demonstrate the page without noticing.
 */
function legal_value(string $value): string
{
    if (legal_is_placeholder($value)) {
        return '<span class="ef-legal-missing">' . e($value) . '</span>';
    }
    return e($value);
}

/**
 * The banner shown at the top of an unfinished document.
 *
 * Returns an empty string once every value is filled in, so the pages need no
 * conditional of their own.
 */
function legal_notice(?array $values = null): string
{
    $missing = legal_missing($values);
    if ($missing === []) {
        return '';
    }

    $list = count($missing) === 1
        ? $missing[0]
        : implode(', ', array_slice($missing, 0, -1)) . ' and ' . end($missing);

    return '<div class="ef-alert ef-alert-error" style="margin-bottom:28px;">'
         . '<strong>This document is not finished.</strong> '
         . 'The ' . e($list) . ' still need to be supplied by the project team, in '
         . '<code>includes/legal.php</code>. Everything else on this page describes '
         . 'what EduFlex actually does and was written from the source code. '
         . 'This banner disappears on its own once the remaining values are filled in.'
         . '</div>';
}

/**
 * A phone number as a tel: link.
 *
 * Android runs EduFlex in a WebView, so a tel: link on a participant's phone
 * dials rather than forcing them to copy digits off a screen. The href is
 * stripped to digits and a leading plus; the visible text keeps the spacing,
 * which is what makes a number readable.
 */
function legal_phone_link(string $phone): string
{
    $dial = (string) preg_replace('/[^0-9+]/', '', $phone);

    return '<a href="tel:' . e($dial) . '">' . e($phone) . '</a>';
}

/**
 * The "Who to Contact" block, rendered once and shown in two places.
 *
 * Kept as one function so privacy.php and app/support.php cannot drift apart.
 * The wording follows the approved consent form; the only change is "and or"
 * to "or" in the closing sentence, which was a typing slip on the form and
 * alters no meaning.
 *
 * @param bool $compact drop the explanatory lead, for a narrow card
 */
function legal_contact_block(bool $compact = false): string
{
    $out = '';

    if (!$compact) {
        $out .= '<p>You can ask the research team any question about this study, before you '
              . 'take part and at any point afterwards. Any of the following will answer:</p>';
    }

    $out .= '<ul class="ef-contact-list">';
    foreach (LEGAL_TEAM as $person) {
        $out .= '<li>'
              . '<span class="ef-contact-name">' . e($person['name']) . '</span>'
              . '<span class="ef-contact-role">' . e($person['role']) . '</span>'
              . '<span class="ef-contact-phone">' . legal_phone_link($person['phone']) . '</span>'
              . '</li>';
    }
    $out .= '</ul>';

    $out .= '<p>Research adviser: <strong>' . e(LEGAL_ADVISER) . '</strong>, reachable through '
          . 'the College of Computer Studies on ' . legal_phone_link(LEGAL_INSTITUTION_PHONE)
          . '.</p>';

    $out .= '<p>This research project has been reviewed and scrutinised by the technical panel '
          . 'of the University of Cebu as part of the completion requirement for the Bachelor of '
          . 'Science in Information Technology programme. For questions about the project itself, '
          . 'contact the College of Computer Studies or the University of Cebu on '
          . legal_phone_link(LEGAL_INSTITUTION_PHONE) . '.</p>';

    return $out;
}

/**
 * What EduFlex is currently configured to send material to.
 *
 * Read from config/ai.php rather than written down, so the statement on the
 * privacy page is about the system as it is running rather than as somebody
 * remembered it. With the mock driver nothing leaves the server at all, and a
 * participant is entitled to know which of those two situations they are in.
 *
 * @return array{sends:bool, driver:string, model:string}
 */
function legal_ai_disclosure(): array
{
    $driver = defined('AI_DRIVER') ? (string) AI_DRIVER : 'mock';

    return [
        'sends'  => $driver !== 'mock',
        'driver' => $driver,
        'model'  => defined('AI_MODEL') ? (string) AI_MODEL : '',
    ];
}
