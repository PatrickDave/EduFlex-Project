# EduFlex — working notes for Claude

Read this before changing anything. It records decisions that are expensive to
rediscover and rules that are easy to break by accident.

## What this is

EduFlex: AI-Powered Personalized Learning Companion. A BSIT capstone project at
the University of Cebu Main, College of Computer Studies. Patrick Cagas is the
group leader and lead researcher.

**Oral defense: second or third week of November 2026. Feature freeze 19 October.**

The system takes a document a student uploads, reads it, works out what topics it
covers, writes practice questions from it, scores the answers, and tracks mastery
per topic. A chat companion answers questions from that same material.

## Stack and how to run it

- PHP 8.x, MySQL 8.0 / MariaDB, Apache. Patrick runs XAMPP on Windows.
- Bootstrap 5.3.3 and jQuery 3.7.1, vendored under `assets/vendor/`.
- Android is a WebView over these same responsive pages. It is not a second build.

```
# FIRST INSTALL ONLY. This drops and recreates the eduflex database.
mysql -u root < database/01_schema.sql

# An existing database with data in it: adds what is missing, drops nothing.
php tools/migrate.php            # show what is missing
php tools/migrate.php --apply    # add it

# run every PHP test suite: no database, no server, no API key, no internet
./run_tests.sh
```

Browser tests need Apache and MySQL running plus `pip install playwright`:

```
python3 tests/runner_browser_test.py    # practice end to end
python3 tests/chat_browser_test.py      # the companion end to end
```

Both drop and recreate the `eduflex` database. Never point them at data worth
keeping.

## Rules that are easy to break

1. **Never hand-edit `app/*.php`.** Those eleven pages are generated. Edit the page
   body or `SHELL` in `build_pages.py`, then run `python3 build_pages.py`. A
   hand edit is silently destroyed on the next run.
2. **`config/ai.php` holds the API key.** It is in `.gitignore`. Never commit it,
   never echo it into a page, never put a key anywhere else in the tree.
   `config/ai.example.php` is the committed template with an empty key; a fresh
   clone copies it to `config/ai.php`. Keep the two in step when adding a
   constant, or a teammate's clone dies on an undefined constant.
3. **No external network requests.** Bootstrap, jQuery and the Inter font are all
   vendored on purpose, so the system renders identically in a defense room with
   no wifi. Do not add a CDN link, a Google Fonts link, or a runtime fetch.
4. **`build_pages.py` bodies are Python strings.** A backslash in generated
   JavaScript needs doubling. A `/\n/g` regex written once already shipped as a
   literal newline and broke the whole companion page.
5. **Mastery thresholds are duplicated** in `includes/helpers.php` and
   `assets/js/eduflex.js`. Change both or the screen and the database disagree.
6. **`BLOOM_WEIGHTS` in `includes/helpers.php` must match the `bloom_level`
   table** in the schema. The table exists so Chapter III can cite the weights
   without reading code.
7. **Use `CURRENT_TIMESTAMP`, not `NOW()`.** The test suites run on SQLite.
8. **A notification must never break the event that caused it, and must never be
   written by a page render.** Write it from the function that performs the
   event, through `notify()`, which swallows and logs every failure. A write on
   render makes the unread count climb every time anyone refreshes. Both rules
   are asserted in `tests/notifications_test.php`.
9. **One password rule.** `auth_password_error()` in `includes/auth.php` is it.
   Registration and the change-password form both call it. Do not write a second
   length check next to a second form.
10. **Security headers and error hardening come from `auth_boot()`.** One call
    site, in `includes/security.php`. Do not add a second, and do not add a page
    that bypasses `auth_boot()`.
11. **The CSP is `default-src 'self'`.** That is what makes rule 3 enforceable
    by the browser rather than a promise. Adding a CDN host to the policy to
    make something work defeats the point; vendor the asset instead.
12. **Never validate a redirect target with a blocklist.** `auth_safe_redirect_target()`
    accepts only what it recognises. The inline check it replaced rejected
    `//host` but allowed `/\host`, which was a live open redirect. See README 1c.
13. **Write `attempted_at` and friends from PHP, not from the column default,
    wherever a window is compared.** SQLite's `CURRENT_TIMESTAMP` is UTC and
    PHP's `date()` is local, so the login throttle silently counted nothing on
    this machine until both sides used one clock.
14. **`getimagesize()` does not prove a file is an image.** Given the 8-byte PNG
    signature followed by anything at all, it reports a valid `image/png` and
    reads the dimensions out of the payload. Any upload check that trusts it
    alone is not a check. `avatar_header_is_intact()` in `includes/avatar.php`
    parses the header properly; reuse it rather than writing a second one.
15. **Nav icons live in `ef_nav_icon()` in `partials/sidebar.php`**, inline and
    `stroke="currentColor"` so they take the colour of the row. Do not add an
    icon font or an SVG sprite from a CDN; rule 3 forbids it.
16. **Never destroy Patrick's development data.** He works in the same database
    you test against, and losing it costs him a re-registration and a re-upload
    every time. Three specific prohibitions, all of which have already been
    broken once:
    - **Never run `mysql < database/01_schema.sql` against a database in use.**
      It opens with `DROP DATABASE`. To apply a schema change use
      `php tools/migrate.php --apply`, which adds what is missing and drops
      nothing. Reimport only for a genuinely fresh install, and ask first.
    - **Never use a blanket `DELETE FROM user`** to clean up after testing. It
      takes his account with yours. Delete the specific test emails you created:
      `DELETE FROM user WHERE email IN ('smoke@example.com', ...)`.
    - **Check before you clean.** `SELECT user_id, email FROM user` first. If
      there is an account you did not create, leave it and everything under it.
    A schema change is three files now: `01_schema.sql` for fresh installs,
    `02_migrations.sql` plus the list in `tools/migrate.php` for existing ones,
    and `SCHEMA-NOTES.md` for the reason.
17. **A PDF that extracts cleanly is not the same as a PDF that extracted
    correctly.** `pdf_text_confidence()` detects capitals stranded inside words
    and nothing else, and a subset font with no ToUnicode map shifts every
    letter instead, producing all-capitals gibberish that scores a perfect
    1.000. `pdf_text_looks_garbled()` is the second check; both run, and either
    one warns. Its thresholds were measured against real samples and are
    recorded in `tests/extract_test.php`. Do not tighten them by guess, and do
    not add a minimum token count: run-together text has few tokens by
    definition, which is the signal itself.
18. Prefer `Read`/`Edit` over shell redirection for file work.

## The mastery model

This is what the whole study rests on. It is arithmetic, never a model call:
deterministic, free, and explainable to a panel.

```
M = ( SUM(w_i * c_i) / SUM(w_i) ) * 100
w_i            = bloom_weight * recency_weight
bloom_weight   = Remember 1.0, Understand 1.2, Apply 1.5,
                 Analyze 1.8, Evaluate 2.0, Create 2.2
recency_weight = 1 / (1 + 0.15k)      k = how many answers ago
```

Bands: mastered >= 85, developing 70-84, weak < 70, "No data" under 5 scored
items (`MASTERY_MIN_ITEMS`).

`mastery_recalculate()` recomputes from a topic's entire answer history rather
than adjusting the previous figure, so the number cannot drift. Keep it that way.

`tests/attempts_test.php` holds the arithmetic worked out by hand: recent-correct
53.49 against recent-wrong 46.51, and the same answer worth 71.67 at Create but
34.33 at Remember. Any change to the formula must be proved there first, and
Chapter III updated to match.

## Rules the AI features must keep

These are the claims the study makes. Breaking one quietly is worse than a
crash, because nobody notices until the panel does.

**Answers are never trusted from the browser.** `attempt_load()` deliberately
does not select `correct_answer`. The answer reaches the page only in the reply
to a submitted answer, after the learner has committed. Correctness is decided in
PHP by comparing against the stored value.

**A learner cannot answer the same item twice** in one attempt. A duplicate would
double-count toward mastery.

**The companion answers only from the learner's own material.** Retrieval runs
first, only those passages go to the model, and the prompt tells it to say so
plainly when they do not contain the answer. A confident answer drawn from the
model's own training is exactly the failure this design prevents.
`chat_system_prompt()` is quotable in Chapter III as the mechanism.

**Citations are verified.** `chat_parse_reply()` discards a citation to a passage
the model was never given, so an invented source cannot reach the screen.

**Generated questions are validated before storage.** `questions_validate()`
rejects: answer not among its own options, not exactly 4 options, duplicate
options, "all/none of the above", a duplicate question in the set, missing or
trivial question text. Under 4 usable questions rejects the whole set. The
answer-not-in-options check is the critical one: it would mark a learner wrong
for being right and permanently corrupt their mastery score.

**Every learner-scoped query filters by `user_id` in SQL**, usually by joining
`learning_resource`. Ownership is never assumed from a session variable alone.

## Quota discipline

Provider calls are the only part of the system that costs money, and Patrick is
on a free tier.

- Topic detection samples 12 chunks spread across a document. The team's own
  155-page manuscript is 205 chunks and costs 12 calls, not 205.
- Question generation is one call per set of 8. Sets are stored and reused.
- `practice_start_topic()` resumes an open attempt, then reuses an unattempted
  stored set, and generates only when nothing is free.
- Chat is one call per question: at most 4 passages, history capped by
  `CHAT_HISTORY_BUDGET`.

Do not add a feature that calls a provider on page load.

## Layout

```
includes/
  auth.php        register, login, logout, session guard
  helpers.php     escaping, CSRF, flash, mastery bands, Bloom weights
  ai.php          AiProvider: openai-compatible, gemini, mock
  extract.php     PDF and DOCX text extraction, chunking
  resources.php   upload, processing, listing, deletion
  topics.php      topic detection
  questions.php   question generation and the validator
  attempts.php    attempts, scoring, mastery, recommendation
  chat.php        the companion: retrieval, grounding, citations
  stats.php       every read model the screens use
  notifications.php  notify(), reading, unread count, the two write rules
  support.php     support requests: the fixed type list and validation
  security.php    response headers, error output, login throttling
  avatar.php      profile pictures: validation, storage, initials fallback
  rubric.php      the arithmetic and Markdown behind the rubric run
  legal.php       the study facts and the Who to Contact block behind
                  privacy.php, terms.php and the Support page
  manuscript.php  the seven modules and the reference list, taken from the
                  manuscript, rendered by the landing page
  subscription.php  plans, the free/premium tiers, the Settings plan card
  exams.php       mock examinations: topic choice, assembly, breakdown
app/              eleven GENERATED pages, plus app/actions/*.php endpoints
partials/         sidebar.php and topbar.php for the app, public_footer.php
                  for index/login/register/privacy/terms
database/         01_schema.sql (fresh install, destructive),
                  02_migrations.sql (existing database, safe), SCHEMA-NOTES.md
tests/            sixteen PHP suites, two Playwright suites
tools/            rubric_run.php (the only script that spends quota),
                  migrate.php (schema changes without losing data)
build_pages.py    regenerates app/*.php from one shell template
```

`AI-OPTIONS.md` covers provider choice. `SETUP.md` is the install guide.
`README.md` section 4 documents the mastery model, 6b the practice loop, 6c the
companion, 6d what notifications say and when, 6e export and delete, 6f profile
pictures. `README.md` 1b lists every security decision and 1c the one
exploitable defect the hardening pass found.

## Test counts

990 PHP checks across sixteen suites, all passing, plus 83 browser checks:

```
auth 40 | extract 44 | ai 63 | questions 73 | attempts 81 | chat 69
notifications 77 | support 39 | settings 60 | security 79 | avatar 87
rubric 69 | legal 76 | manuscript 39 | subscription 48 | exams 46
runner_browser 54 | chat_browser 29
```

5 of the 32 extraction checks need the PHP zip extension, because building a
.docx fixture needs `ZipArchive`. Patrick enabled `extension=zip` on 14
September 2026, so the suite reports 32; on a machine without it the DOCX block
reports SKIP and the suite reports 27, and DOCX uploads fail at runtime too.

Add tests with the rest of the work, not after. Several real bugs were caught
only because a test asserted something specific: a question containing a year
crashed retrieval, a repeated button press paid for a second set, the mock
leaked a passage tag into its answer.

## Schema deviations from Chapter III

Seven additions, documented in `database/SCHEMA-NOTES.md`, all still needing to
be written into the manuscript:

1. `learning_activity.topic_progress_id`
2. `topic_progress.scored_items`
3. the whole `resource_chunk` table
4. seven extraction columns on `learning_resource`
5. the `bloom_level` reference table
6. the whole `login_attempt` table, for login rate limiting
7. `user.avatar_path`, for profile pictures
8. `activity_item.topic_progress_id` and `activity_item.bloom_level`, so an
   item can carry its own when a mock examination spans several

Unresolved: `topic_progress` is keyed on `(user_id, resource_id, topic_name)`, so
one topic appearing in two uploads produces two separate mastery scores. The
`subscription` table still contradicts the non-commercial decision in Chapter I.

## Manage Subscription and Generate Mock Examinations

Both were listed in the Chapter III List of Modules and missing from the code.
Patrick asked for both on 16 September 2026 and both were built that day, so the
system now does all 34 sub-modules and the landing page says so.

**Manage Subscription** is the plan card on Settings, backed by
`includes/subscription.php`. It reads the `subscription` row that has existed
since registration and that nothing read before.

The manuscript never actually said EduFlex is non-commercial. That claim lived in
three code comments and in this file, and a search of all 134,000 characters
found nothing about EduFlex's own pricing; the data dictionary says plan_type is
"free or premium". Patrick chose to keep premium in the design as future work, so
the card shows the free plan, lists what premium would add, and states plainly
that it is not offered and cannot be bought. `subscription_cost_statement()` is
the single source for what a learner is told about money, and `terms.php` reads
it too, because that page used to claim "there is no subscription".

**Generate Mock Examinations** is `includes/exams.php`. Twenty questions across
up to three topics, weakest first.

Three design points worth keeping:

- **It assembles before it generates.** A 20-question exam written from scratch
  is three provider calls every time. It takes questions the learner has not been
  asked from sets that already exist and generates only the shortfall, so a
  learner who has practised gets an exam for nothing. `tests/exams_test.php`
  asserts a fully stocked exam makes zero calls.
- **It excludes by question text, not item id.** Building an exam copies the
  chosen questions into the exam's own activity, because an item belongs to one
  activity. Answering the copy leaves the original untouched, so an id-based
  check served the same question again in the next exam. The test caught it.
- **Exam answers count toward mastery identically**, for every topic touched.
  `attempt_finish()` recalculates each of them through
  `attempt_topics_touched()`, rather than the single topic a practice set has.

## Still to do

1. **The AI-output validation rubric run. Start here. Only Patrick can do it.**
   The harness is built and tested: `tools/rubric_run.php`. It refuses to run
   against the mock, because the mock returns questions written to pass
   validation and would report a 0 percent rejection rate that measures nothing.

   ```
   # 1. set AI_DRIVER, AI_API_KEY and AI_MODEL in config/ai.php
   # 2. upload a real document, let EduFlex read it, run topic detection
   # 3. then:
   php tools/rubric_run.php --sets=12 --out=docs/rubric-run.md
   ```

   It prints and writes a Markdown report: questions returned, how many
   `questions_validate()` discarded, the count for each reason, and how many
   whole sets fell under the four-usable floor. Paste it into Chapter IV. Twelve
   sets is twelve provider calls; it asks for confirmation before spending.
2. Patrick has still not run topic detection, generation or chat against a real
   provider. The live HTTP call is the one untested path in the system, and
   item 1 is what would finally exercise it.
3. Serve over HTTPS. The `Secure` cookie flag and HSTS are already written and
   turn themselves on when `security_is_https()` returns true, so this is a
   deployment step, not a code change.
4. Nothing outstanding on the consent documents. `privacy.php` and `terms.php`
   are public, linked everywhere, and complete: Patrick supplied the five study
   facts on 15 September 2026 and `includes/legal.php` now holds them, so the
   unfinished banner is gone. `tests/legal_test.php` asserts none of the five
   is a placeholder, so reverting one to a TODO fails the suite rather than
   quietly showing a red banner to participants again.

   The "Who to Contact" block from the approved consent form is rendered by
   `legal_contact_block()` in `includes/legal.php` and appears in two places:
   section 11 of `privacy.php`, and a card on the Support page. One function so
   the two cannot disagree about a phone number.

   **The adviser's personal mobile is deliberately absent.** Patrick asked on
   15 September 2026 that the four student researchers be listed with their
   numbers and the adviser not be. He is named, and reachable through the
   published college line. `tests/legal_test.php` asserts his mobile appears
   nowhere, so pasting the whole consent form back in fails the suite. Do not
   add it without asking him.

   Two things to check with the adviser before the defense, neither a code
   change:
   - The contact address is a personal Gmail rather than an institutional one.
     Some ethics reviewers expect a university address on a participant-facing
     document.
   - The retention line says data is deleted at the end of the academic year in
     which the study concludes. If the ethics submission says something else,
     the submission is what a reviewer will hold him to; change the constant to
     match it.

## Manuscript fixes outstanding (no code)

Cover page "A Proposal / Capstone 41"; second-person leftovers in Chapter III
Treatment of Data and Chapter IV demographics; broken LaTeX formulas; inverted
success-rate formula; purposive versus convenience sampling contradiction;
comparative matrix totals (Udemy is about 32 percent, not 47); ten citations
missing from References; the "GPT-based" sentence if the provider is not GPT;
storyboard figures 25, 29 and 37, which still show Materials as a separate screen
from the companion.

The companion and the materials library were merged into one screen at Patrick's
request. That merged two SCREENS, not two modules. The seven modules and 34
sub-modules in the Chapter III functional decomposition are unchanged.

Added by the notifications and settings layer, still to be written up:

1. Chapter III can now cite a real withdrawal mechanism. Settings has Export my
   data and Delete my account, neither of which needs the project team, and
   `tests/settings_test.php` proves the deletion is complete and scoped.
   `auth_export_data()` and `auth_delete_account()` are the quotable mechanism.
2. Chapter V should record notification preferences as future work. There is no
   preferences table and no toggles, deliberately, because a switch that changes
   nothing is worse than no switch. If the storyboard shows toggles, cut them
   from the figures or label them future work.
3. Get Support is implemented as a recorded request with no helpdesk behind it,
   and the page says so. Do not let the manuscript imply a staffed support desk.
4. `privacy.php` doubles as the participant information sheet, so Chapter III's
   informed-consent and right-to-withdraw sections can cite it directly rather
   than describing a document that only exists in the manuscript. It states
   plainly that the project team can read the database, which is the disclosure
   an ethics reviewer will look for.

## Working with Patrick

- Conversation can be informal. Anything written for the manuscript must be
  formal academic prose.
- Prefers numbered and bulleted structure over dense paragraphs.
- Prefers complete, ready-to-run files over snippets.
- No em dashes.
- Say plainly when something is not done or not tested. He is presenting this
  work to a panel and needs to know exactly what he can and cannot claim.
