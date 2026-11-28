# EduFlex — working notes for Claude

Read this before changing anything. It records decisions that are expensive to
rediscover and rules that are easy to break by accident.

## What this is

EduFlex: AI-Powered Personalized Learning Companion. A BSIT capstone project at
the University of Cebu Main, College of Computer Studies. Patrick Cagas is the
group leader and lead researcher.

**Final oral defense: 4 to 12 December 2026.** Patrick confirmed this on
18 November 2026; this file previously said the second or third week of
November, which was wrong. Feature freeze 19 October.

The system takes a document a student uploads, reads it, works out what topics it
covers, writes practice questions from it, scores the answers, and tracks mastery
per topic. A chat companion answers questions from that same material.

## Stack and how to run it

- PHP 8.x, MySQL 8.0 / MariaDB, Apache. Patrick runs XAMPP on Windows.
- Bootstrap 5.3.3 and jQuery 3.7.1, vendored under `assets/vendor/`.
- Android is a WebView over these same responsive pages. It is not a second
  build. The project is in `android/`; see "The Android wrapper" below.

```
# FIRST INSTALL ONLY. This drops and recreates the eduflex database.
mysql -u root < database/01_schema.sql

# An existing database with data in it: adds what is missing, drops nothing.
php tools/migrate.php            # show what is missing
php tools/migrate.php --apply    # add it

# run every PHP test suite: no database, no server, no API key, no internet
./run_tests.sh
```

Browser tests need Apache and MySQL running plus `pip install playwright`.

**On Patrick's machine the command is `python`, not `python3`.** Typing
`python3` there opens the Microsoft Store prompt and does nothing, which is
exactly how it reads as "the test is broken". Open PowerShell, then:

```
cd C:\xampp\htdocs\eduflex-ui
$env:EDUFLEX_CHROMIUM = "C:\Program Files\Google\Chrome\Application\chrome.exe"

python tests\modules_browser_test.py   # all 34 Appendix R sub-modules
python tests\video_browser_test.py     # the explainer dialog
python tests\password_browser_test.py  # the show/hide password control
python tests\runner_browser_test.py    # practice end to end
python tests\chat_browser_test.py      # the companion end to end
```

The `$env:` line lasts only for that window, so set it again each time you open
a new one. On Mac or Linux it is `python3` and `export EDUFLEX_CHROMIUM=...`,
and that variable can be left unset once `playwright install chromium` has run.

**`runner_browser_test.py` and `chat_browser_test.py` drop and recreate the
`eduflex` database. Never point them at data worth keeping.** The other three
are safe against Patrick's working install: `video` and `password` only read
public pages, and `modules` creates and deletes its own throwaway account.

`playwright install chromium` times out on this connection. Point the suites at
the Chrome that is already installed instead:

```
EDUFLEX_CHROMIUM="C:/Program Files/Google/Chrome/Application/chrome.exe"
```

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
18. **Never link `eduflex.css` or `eduflex.js` without `asset()`.** Write
    `<?= asset('assets/js/eduflex.js') ?>`, and in `build_pages.py`'s SHELL the
    `../` form. It appends `?v=<filemtime>` so the URL changes when the file
    does. Apache sends no `Cache-Control` for these, so a browser is free to
    reuse an old copy and will. On 12 October 2026 the password reveal shipped
    working and fully tested, and on Patrick's screen the icon sat in the wrong
    place and did nothing: the markup is rendered by PHP so it appeared at once,
    while the stylesheet that positions it and the script that drives it both
    came from his cache. **This bug looks like broken code to the person
    reporting it and like working code to the person who wrote it**, which is
    why it is worth a rule rather than a comment. `tests/auth_test.php` fails if
    any page in the root or in `app/` links either file bare.
19. Prefer `Read`/`Edit` over shell redirection for file work.
20. **A test suite must never write to, or delete from, a real `storage/`
    directory.** `tests/avatar_test.php` ended by deleting every file in
    `storage/avatars/` and asserting the directory was empty, against the REAL
    one, so `./run_tests.sh` destroyed Patrick's uploaded profile picture every
    single run. He reported it on 18 November 2026 as "the picture does not
    stay"; it stayed fine until the next test run. Its own docblock already
    claimed it ran against a scratch directory, which is how it went unnoticed.

    The fix is `avatar_root()`, overridable only under `PHP_SAPI === 'cli'`,
    the same shape as the injection in `mail_config()`. **Override the ROOT,
    not `avatar_dir()`**: a stored path keeps its literal `storage/avatars/`
    prefix and is resolved in three other places, so moving only the directory
    desynchronises writing from reading and every lookup misses. That was tried
    first and broke sixteen checks.

    Two guards now: the suite asserts up front that it is pointed somewhere
    other than the real directory, and the teardown refuses to delete anything
    if those two paths ever resolve the same. Proved by leaving a sentinel file
    in the real directory and running the whole suite over it.

    **This is rule 16 in a place nobody was looking.** Rule 16 is about not
    running destructive SQL by hand; this was a green test suite quietly doing
    it on every run. When a suite touches the filesystem, check WHICH
    filesystem.

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
  admin.php       the support queue: who may read it, and moving one along
app/              seventeen GENERATED pages, plus app/actions/*.php endpoints
partials/         sidebar.php and topbar.php for the app, public_footer.php
                  for index/login/register/privacy/terms
database/         01_schema.sql (fresh install, destructive),
                  01_schema_shared_host.sql (DERIVED, for shared hosting),
                  02_migrations.sql (existing database, safe), SCHEMA-NOTES.md
tests/            eighteen PHP suites, five Playwright suites
tools/            rubric_run.php (the only script that spends quota),
                  migrate.php (schema changes without losing data),
                  ai_ping.php (prove a key for one request),
                  make_admin.php (grant or revoke help desk access)
build_pages.py    regenerates app/*.php from one shell template
android/          the WebView APK: an Android Studio project with no
                  dependencies, never yet compiled. See android/README.md
```

`AI-OPTIONS.md` covers provider choice. `SETUP.md` is the install guide.
`README.md` section 4 documents the mastery model, 6b the practice loop, 6c the
companion, 6d what notifications say and when, 6e export and delete, 6f profile
pictures. `README.md` 1b lists every security decision and 1c the one
exploitable defect the hardening pass found.

## Test counts

1544 PHP checks across eighteen suites, all passing, plus 173 browser checks:

```
auth 102 | extract 44 | ai 96 | questions 73 | attempts 81 | chat 69
notifications 77 | support 84 | settings 63 | security 79 | avatar 91
rubric 69 | legal 76 | manuscript 75 | subscription 48 | exams 46
verification 101 | admin 270
runner_browser 54 | chat_browser 29 | video_browser 31 | password_browser 23
modules_browser 36
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
9. `user.is_admin`, for the help desk
10. `trusted_browser` keyed on `(token_hash, user_id)` rather than the token
    alone, so one browser can be trusted for more than one account

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

## The explainer video

Added to the How it Works section of the landing page on 21 September 2026.
`assets/video/eduflex-explainer.mp4`, 1920x1080, 3:18, about 8.6 MB, committed
to the repository because rule 3 means the page has to work in a room with no
wifi and a video fetched from YouTube would not. `assets/video/.htaccess`
declares `text/vtt`, which XAMPP's `mime.types` does not.

**The `.srt` that came with the video does not match it.** It is from the
previous cut: it opens on the title card where v2 opens on "The Challenge", and
its last cue ends at 3:37 against the file's 3:18. It was converted, wired in,
and removed again once the frames were actually looked at. Do not convert
`Claude outputs/EduFlex-Explainer.srt` and add it back. The video carries
burned-in captions, so nothing is missing; a `<track>` goes in only when a
caption file matching v2 exists. `tests/manuscript_test.php` asserts that any
`<track>` on the page points at a file that is really there, so adding one is
checked rather than silently dead.

The poster is frame 0.6s of the video itself, extracted with headless Chrome.
Without it the player is a black rectangle until someone presses play.

**Re-encoded 27 November 2026, 15.3 MB to 8.6 MB**, so it fits under
InfinityFree's 10 MB per-file cap and can go on the public site. Patrick needs
it there: the adviser wants to glance at the work without being in the room.

- Still 1920x1080, still 3:18. **Downscaling to 720p was rejected** because the
  video is mostly UI screenshots with small text, and a rescale destroys that
  text first. Two-pass x264 at 290 kb/s holds it because the content is nearly
  static slides.
- Audio went 130 kb/s stereo to 64 kb/s mono. One narrator, so stereo carried
  nothing, and that paid for most of the saving by itself.
- `-movflags +faststart` so it plays before it has finished downloading.
- Checked by eye at 12s, 75s and 150s against the original, not just by file
  size. Small UI text and burned-in captions stay legible.
- The exact command is in DEPLOY.md section 5.

**There IS an ffmpeg on this machine**, contrary to what this file used to say:
`%USERPROFILE%\.vscode\extensions\kilocode.kilo-code-*\bin\ffmpeg.exe`, bundled
with a VS Code extension. It has libx264, libvpx-vp9, aac and libopus.

**The 15.3 MB master is `Claude outputs\Eduflex-Explainer v2.mp4`**, gitignored
and local only. Re-encode from that, never from the 8.6 MB copy, or each
generation loses more.

**It opens in a dialog, and there is only ever one `<video>` on the page.** Both
"See How it Works" in the hero and the poster in the How it Works section open
the same native `<dialog>` at the foot of `index.php`. A native dialog rather
than a Bootstrap modal because the Bootstrap CSS is already loaded on that page
but the JS bundle is not, and 80 KB to open one panel is a poor trade; `<dialog>`
also brings the focus trap, Escape, and focus restoration for free.

Three things there are load-bearing, all asserted in
`tests/video_browser_test.py`:

- **`preload="none"`.** Most visitors never press play, and 8.6 MB on every
  landing page view would be indefensible. Nothing is requested until the dialog
  opens.
- **`initVideoDialog()` pauses AND rewinds on close.** A closed `<dialog>` is
  only hidden, so without this the narration carries on from an element nobody
  can see or find. Every route out, including Escape, goes through the one
  `close` handler.
- **The hero trigger stays an `<a href="#how">`.** The handler prevents the
  jump, but with no JavaScript the link still scrolls to the poster. A button
  that does nothing when a script fails to load is worse than a link that does
  less.

`.ef-video-play` is centred with a `transform`, so the blanket
`transform: none !important` in the reduced-motion block has to be overridden
for it or the badge lands in the corner. Both suites check this.

Fixed 18 November 2026: `index.php` used to scroll sideways by about 123px at
390px wide. See "The sideways scroll on the landing page" below. It was never
caused by the video.

## The first real topic detection, and what it caught

20 October 2026. The manuscript was ingested for user 10 as resource 10 and
detection run against it: **29 chunks, 12 sampled, 10 succeeded, 2 failed with
Gemini's transient 503, 24 topics kept.** That is the material the rubric run
should use, not `Discrete_Math_1`, which is 2 chunks and would have measured its
own thinness.

It returned **"One sentence on what the excerpt says about it"** as a topic, and
it was stored and shown as something to study. That string is the literal
placeholder from the JSON shape in `topics_system_prompt()`. The guard that
should have stopped it rejects a name over ten words, on the reasoning that a
sentence in a name field is a summary in the wrong place; the phrase is NINE
words and walked straight through.

`topics_is_prompt_echo()` is the fix, and it checks against the template's own
strings, deliberately kept next to the prompt they come from. **If the JSON
shape in `topics_system_prompt()` is ever reworded, reword the placeholder list
with it**, or the guard silently stops guarding. `tests/ai_test.php` asserts the
two strings still appear in the prompt for exactly that reason, and asserts that
seven real topics from this document are not caught by the fix.

The bad row (topic_progress_id 44) was deleted after confirming it had no
mastery, no scored items and nothing referencing it.

Worth knowing for the write-up: the 24 topics contain real near-duplicates
("Self-Directed Learning", "Self-Directed Learning Technology", "Self-Directed
and Self-Regulated Learning"). Merging is per-reply, so the same concept found
in two different chunks survives twice. Not a defect, but a panel may ask.

## The Appendix R module audit

`tests/modules_browser_test.py` walks the real application as a learner and
reports, for each of the 34 sub-modules in the adviser-approved Appendix R,
whether it actually works. Run it before a consultation: it prints the
per-module tally that feeds the consultation form's scoring, which is one point
per module per user, Guest 1 plus Learner 7, so eight points.

**24 November 2026: 36 of 36 checks pass, 8 of 8 points**, re-run after Andrew's
support replies and user management landed. 25 October was 35 of 35. The first
consultation
on 20 September scored 3 of 8, or 37.5 percent.

It is self-contained and non-destructive. It registers its own throwaway
account, uploads its own `.txt` through the real form, and removes the account
afterwards through `auth_delete_account()`, the learner's own withdrawal
mechanism, which also deletes the file it uploaded.

`tests/modules_fixture.php` writes the three things a browser cannot without
spending quota: topics, one conversation turn, and one completed practice
attempt. Only the question text is invented. The attempt runs through
`attempt_start()`, `attempt_answer()` and `attempt_finish()`, so mastery,
banding and the recommendation are genuinely computed rather than written by
hand. Without that attempt the dashboard sits in its empty state, because
`stats_empty_state()` suppresses the recommendation until there are scored
answers, and sub-modules 4.4, 4.5, 5.1, 5.2 and 5.4 cannot be exercised at all.

**Two traps this suite exists to remember, both hit while writing it:**

- **Seed data must never point at a real learner's file.** The first version
  copied `storage_path` from Patrick's manuscript row, and
  `auth_delete_account()` removes uploaded files from disk. Deleting the
  throwaway accounts would have taken his 16 MB document with them. It was
  caught one command before the delete. `modules_fixture.php` now refuses any
  address not starting `modaudit_`, and the suite uploads its own file so there
  is no shared path at all.
- **`form button[type='submit']` is not the form you mean.** The sidebar's
  sign-out form comes first in the DOM, so a loose selector signs the run out
  halfway through and every later check fails for the wrong reason. Target the
  action: `form[action*='support_submit'] button`.

Two sub-modules cannot be proven without a live model call: 4.1 Detect Topics
and 3.1 Ask the Companion. The suite checks the control is present and wired and
says so in its output rather than claiming more than it has shown.
`tools/ai_ping.php` proves the provider itself for one request.

**The approved Appendix R and `includes/manuscript.php` do not list the same
sub-modules.** Both total 34, but Appendix R Module 1 has "View Landing Page"
and no "Manage Subscription", while `manuscript.php` has "Manage subscription"
and no "View Landing Page". The landing page therefore advertises a sub-module
the adviser did not approve and omits one he did. The wording differs throughout
as well, which is cosmetic; that swap is not.

## The support queue (Module 7's missing half)

Built 1 November 2026, after the second consultation scored Module 7 at zero.
The learner side had worked all along: the module audit found all five
sub-modules passing. What was missing was anybody on the other end.

**`support_request.status` was written as 'open' at creation and no code
anywhere changed it.** So "View Request Status" showed "Recorded" forever and a
submitted request went nowhere visible. The adviser asked for an admin view.

`app/admin.php` lists every request with who sent it, filters by status and by
type, and moves one along. `admin_set_status()` is the first thing in EduFlex
that writes to `support_request` after creation, and therefore the first thing
that makes sub-module 7.2 mean anything.

**This is the one part of EduFlex that deliberately reads across learners.**
Everywhere else ownership is enforced in SQL by filtering on `user_id`; here the
whole point is to see other people's requests, so the guard moves from the query
to the door. Three things hold it:

- **`admin_require()` is called in BOTH `app/admin.php` and
  `app/actions/admin_status.php`.** A gate on the screen is not a gate on the
  system: without the second one, anyone signed in could POST and change another
  learner's request, link or no link. Verified in a browser by POSTing at the
  endpoint directly as a learner.
- **It runs in the `{requires}` slot**, which `build_pages.py` emits before any
  output. A gate after the HTML has started cannot redirect.
- **`is_admin` is set by hand in phpMyAdmin.** Nothing in the interface grants
  it: no promote button, no admin registration, no "first user is admin".
  `tests/admin_test.php` greps `includes/` and `app/actions/` for any statement
  that writes the column and fails if one appears, comments stripped first.

```
UPDATE user SET is_admin = 1 WHERE email = 'patrickcagas123@gmail.com';
```

**Three bugs this flushed out, all found by testing in a browser rather than by
reading the code:**

1. **`auth_user()` names its columns and `is_admin` was not among them.** The
   flag was correctly set in the database and `admin_is_admin()` read a key that
   was never there, so the team was turned away from their own queue. Any future
   column on `user` is invisible to every caller until it is added to that
   SELECT.
2. **`admin_is_admin()` was in `includes/admin.php`, which only `app/admin.php`
   requires.** On the other eleven pages the function did not exist, so the
   guarded sidebar call silently skipped and the Support Queue link appeared
   nowhere. It now lives in `includes/auth.php`, which every page loads.
3. **The dashboard rendered no flash at all.** `admin_require()` set one before
   redirecting there and it was swallowed, then surfaced later on whichever page
   next read `flash_get('error')`. The dashboard now renders it, which also
   fixes that for every other redirect that lands there.

Adding `is_admin` broke five test suites that build their own SQLite `user`
table. That is the third time a new column has done this; the fixtures in
`auth`, `avatar`, `security`, `settings` and `verification` all need it.

**Worth knowing before the third consultation:** the queue on Patrick's install
is empty, and an empty queue demonstrates nothing. The adviser's note was
"prepare data so that we can see the actual". Submit two or three requests as a
learner first, then show the round trip: submit, see it in the queue, move it to
Being looked at, and watch the learner's own Support page and notifications
change. That round trip is proven end to end in a browser.

## Two doors, and what is behind each

Settled 2 November 2026 after three rounds. Patrick wanted a separate admin
sign-in; I argued twice for one login with role routing and was overruled, which
was his call to make. What got built is the version he asked for.

**`login.php` and `admin_login.php` are separate pages**, each carrying the same
User / Admin switch from `partials/auth_tabs.php`. The tabs are plain links to
the other page, so the control works with no JavaScript and the address bar
always says which door you are at.

Each door refuses the other's people:

- A learner with the **correct password** at the team door is refused and signed
  back out. The message is identical to a wrong password, so the page cannot be
  used to discover which addresses are admins.
- A team account at the learner door is signed out and sent to
  `admin_login.php?team=1`.

**A query flag, not a flash.** `auth_logout()` destroys the session on the way
across, so a flash set before it dies with the session and one set after it has
no session to live in. The message was written and silently lost until this
changed. Account deletion already solved the same problem the same way.

**What is NOT duplicated is the machinery.** `auth_login()` is still the only
place a password is checked, so there is one password rule, one login throttle
and one email-verification path. A second copy of any of those would be a second
thing to keep correct.

**The two areas do not cross.** A learner never sees a help desk link, not even
one that would be refused; a team account gets the help desk nav and nothing
else. There was a "Learner View" link and a "Help Desk" link, and both were
removed: the team account is a shared address with no learning of its own.

**`eduflex54@gmail.com` is the only admin**, set 2 November 2026.
`patrickcagas123@gmail.com` was revoked at the same time and is now a plain
learner account, with its 2 materials, 29 topics and 5 attempts untouched: the
flag was removed, nothing else.

**The team account cannot reach the learner area either.** The guard is in
`build_pages.py`'s SHELL, so it covers all sixteen generated pages at once and
a new page cannot be added without it. Settings is the one exception, because
that is where the team changes its own password. It reads `admin_is_admin()`,
never a hard-coded address.

Grant and revoke with:

```
php tools/make_admin.php eduflex54@gmail.com
php tools/make_admin.php <email> --revoke
php tools/make_admin.php                     # who has it now
```

Register the account through the normal Sign Up form **in the browser the team
will use**. That gives the browser a seven-day trust grant, so the first
sign-in does not need a code emailed to the team inbox. Creating the row from
the command line skips that grant.

## The team's own Settings

2 November 2026. Clicking Settings as an admin landed on `app/settings.php`, a
learner screen: Personal details, a plan card, an export of "your" learning,
none of which a shared team address has. Worse, `$active` was `'settings'`, so
the sidebar rendered the LEARNER nav and the help desk vanished mid-session.
Patrick reported exactly that.

`app/admin_settings.php` keeps profile picture, change password and delete this
account. Personal details, the plan card and email verification are gone.
**"Your data" became Users**, which is where a participant's withdrawal request
gets actioned: export their data or delete their account, both reusing
`auth_export_data()` and `auth_delete_account()` rather than a second
implementation.

`admin_user()` returns null for a team account as well as a missing one, so a
crafted id cannot export or delete another admin through a screen meant for
participants. Deleting still requires the address typed, and that check is worth
more here than on the learner page because the person typing is not the person
being deleted.

**The learner-Settings exception in the SHELL guard is gone.** It existed only
because that was the team's one way to change a password. The separation is now
complete: an admin reaches no learner page at all.

**`auth_settings_page()` is why the shared endpoints still work.**
`change_password`, `save_avatar`, `delete_avatar` and `delete_account` are
posted to by BOTH settings pages, and all four redirected to `settings.php`.
For an admin the guard then bounced that to the help desk, where nothing reads
`flash_get('password_ok')`: the password really did change and the screen said
nothing. Third time that shape of bug has appeared, after the two sign-in doors
and the dashboard flash.

One wording fix worth keeping: `auth_delete_account()` says "Type **your own**
email address exactly", which is right on the learner page and wrong when an
admin deletes somebody else. `admin_user_delete.php` rewords it rather than
changing the source, because the learner page still needs the original.

## Help and FAQ (Appendix R 7.3)

`app/help.php`, in the learner nav. Nine answers in `<details>` elements:
keyboard-operable, findable by the browser's own search, and working with no
JavaScript.

Every answer is written out. **Nothing on this page costs a provider call**, so
it reads the same every time including in a room with no wifi, and
`tests/admin_test.php` asserts it never calls `ai_provider()`, `chat_ask()` or
`questions_generate()`. The answers that quote numbers read `MASTERY_MIN_ITEMS`
and the band constants rather than hard-coding them.

These answers used to be a card at the bottom of `app/support.php`. They are
not any more: Support links here instead. **One copy, asserted.** Two copies of
the same text is two places to update and one place to forget.

## One browser, two accounts

2 November 2026. Patrick reported that email verification appeared on every
sign-in even after ticking "trust this browser for 7 days".

**`trusted_browser` was keyed on `token_hash` alone, and the cookie holds one
token.** Trusting a second account minted a new token and overwrote the cookie:
the first account's row stayed in the table while the browser no longer held its
token, so the next sign-in as that account asked for a code again. Trust one,
lose the other, alternating forever. His table held four unreachable rows for one
account and three for another, which is what gave it away.

He was switching between his learner account and `eduflex54@gmail.com` in the
same browser, which is a pattern this project created when the two sign-in doors
were built.

**The fix has two halves and both are needed:**

- The key is now `(token_hash, user_id)`. See SCHEMA-NOTES.md 10.
- `trusted_browser_store()` reuses this browser's existing token instead of
  minting a new one. The token identifies the BROWSER; the row's `user_id` and
  `credential_hash` bind it to an account.

The key alone still leaves the cookie being overwritten. The reuse alone cannot
store the second row: the INSERT violates the old primary key and registration
reports "Could not create the account", which is exactly what happened when the
reuse was written first.

**Declining to trust no longer clears the cookie.** It used to, which threw away
the browser token and with it every other account's trust on that machine. The
row for that account is deleted, which is what declining means.

Three things ruled out along the way, each checked rather than assumed: the
cookie path (correct at `/eduflex-ui/`), the `Secure` flag (`security_is_https()`
returns false on localhost, so the cookie is sent over http), and password
rehashing on login (`password_needs_rehash()` is false for every account, so the
credential hash is stable).

Rows created before the fix are unreachable but harmless, and expire within
seven days.

## The verification code landing in Spam

2 November 2026. One real defect, and an honest limit on what can be fixed in
code.

The defect: PHPMailer builds `Message-ID` from `gethostname()`, which on
Patrick's machine is `Charles`, so every code email carried `<...@Charles>`.
That is not a fully qualified domain and reads as misconfigured to a receiving
filter. `includes/mail.php` now sets `$mail->Hostname` from the `from_email`
domain, giving `<...@gmail.com>`, and adds a `Reply-To`.

**That is one weak signal among many and almost certainly not the whole
reason.** The SMTP config was already correct: `username` and `from_email` are
both `eduflex54@gmail.com`, so SPF and DKIM align and the usual cause is ruled
out. What remains is reputation and content, none of it a code problem:

- a brand-new Gmail account with no sending history,
- a body shaped exactly like phishing (six digits, "expires in 10 minutes",
  "never share your code"),
- many near-identical sends during testing,
- mail addressed to the same mailbox it is sent from, which is what happens
  when the team account verifies itself.

Gmail publishes no reasons, so do not claim a code change fixed this.

**What actually makes it a non-issue for the defense** is the trusted-browser
fix above: one verification per browser per seven days, not one per sign-in.
On the demo machine, sign in once, tick trust, and the panel never sees an
email step. Belt and braces on the receiving side is a Gmail filter, from
`eduflex54@gmail.com`, "Never send it to Spam".

An HTML message with EduFlex branding would read as more legitimate than bare
text. That is a genuine improvement and a cosmetic one; it was not done.

## The Android wrapper

Built 18 November 2026, when Patrick chose a WebView APK over a PWA. Lives in
`android/`, with its own README covering install, build and connection.

**Built and running on the emulator, 18 November 2026.** The landing page
renders correctly with no sideways scroll. It has NOT been on a physical
handset, and upload, download, `tel:` and fullscreen video are written but
never tapped on a device. Do not claim those until someone has.

**The first run failed with `net::ERR_CONNECTION_REFUSED`, and the cause was
`adb reverse` not being set.** Worth knowing because refused and timed out
mean different things: refused is something answering to say nothing listens
on that port, which is the phone's own `localhost:8080` before the bridge
exists. A timeout instead points at the firewall or at XAMPP bound to
`127.0.0.1`. **The bridge survives neither unplugging the cable nor restarting
the emulator**, so re-running it is a step, not a one-off. On the emulator
`http://10.0.2.2/eduflex-ui/` needs no bridge at all.

The wrapper exists to supply the five things a bare WebView does not, each of
which silently breaks a feature the capstone is assessed on:

1. **`CookieManager.flush()` in `onPause()`.** Without it the `eduflex_browser`
   cookie never reaches disk, so the seven-day trust is lost on every launch
   and email verification comes back. That is the bug fixed on 2 November,
   reintroduced by the wrapper, and it would look like a new defect.
2. **`onShowFileChooser`.** Without it Upload Material does nothing at all.
3. **Extension to MIME mapping.** EduFlex writes `accept=".pdf,.docx,.txt,.md"`,
   and Android's `EXTRA_MIME_TYPES` understands only MIME types. Hand it
   `.pdf` and the picker greys out every document, with no error.
4. **`DownloadListener`.** Without it Export my data does nothing, and that is
   the withdrawal mechanism Chapter III cites.
5. **`tel:` and `mailto:` handoff**, or the Who to Contact block is dead text.

**Cleartext HTTP is permitted on purpose**, in
`res/xml/network_security_config.xml`. Android 9+ blocks it by default and the
WebView then renders nothing with no stated reason. That file says what to
delete once HTTPS is in place (Still to do, item 3).

**No dependencies at all**, not even AndroidX: framework `Activity`,
`AlertDialog` and `WebView` only, so Gradle downloads the plugin and nothing
else. Deliberate, because large downloads time out on this connection.

**The connection is the part that fails on the day.** `adb reverse tcp:8080
tcp:80` over USB is the one to rehearse, because it needs no network at all,
and it does not survive unplugging the cable. The address is a runtime setting
rather than a compiled constant, so moving between USB, wifi and the emulator
does not mean rebuilding.

## The sideways scroll on the landing page

Fixed 18 November 2026, after sitting open since the video went in. It mattered
because the Android build is a WebView over these pages, so a page that scrolls
sideways on a phone is the mobile product.

**The recorded diagnosis was wrong**, and is worth keeping as a warning. It
blamed the `<pre class="ef-formula">` and the `.ef-table` of Bloom weights in
the Methodology card. Both are innocent: the `pre` already carries
`overflow-x: auto` and `min-width: 0`, and cloning every descendant of that
card into a zero-width probe found nothing with a min-content width over 300px.

The cause is one declaration. `.ef-row` sets `align-items: flex-start`, which
is right for a row, where it stops two cards of unequal height stretching to
match. At 860px the row flips to `flex-direction: column`, and the cross axis
becomes horizontal: the same declaration now means "shrink each card to fit its
content", so the widest content in the card set the width of the whole page.
Restoring `align-items: stretch` inside the 860px block fixes it.

**The tell, and the thing worth reusing: the overflow did not shrink with the
screen.** 513px at a 390px viewport, 511px at 360, 511px at 320. A genuine
content overflow tracks the viewport, because the content is what is too wide.
A number that stays put is a sizing mode, not a wide element. Measuring three
widths is what turned a two-month-old guess into a one-line fix.

`tests/video_browser_test.py` now asserts the whole page at all three widths,
which is what the note in that suite always said to do once this was fixed. One
width would pass again on a layout whose floor merely dropped just under 390.

## Replies on support requests

Added 19 November 2026. A support request is now a conversation: the team
replies from `app/admin_thread.php`, the learner reads it and answers back on
`app/support_thread.php`. Both include `partials/support_thread.php`, so the two
sides cannot render a thread differently. Storage is the new `support_reply`
table, SCHEMA-NOTES.md section 11, which is deviation 9 from Chapter III.

- A team reply on an open request moves it to in progress and notifies the
  learner once, from `admin_reply()`. "Send and mark resolved" does both in one
  step, still one notification.
- A learner reply on a resolved request reopens it; on a closed one it is refused.
- The team is never notified. The bell opens a learner page that team accounts
  are redirected away from, so the count could never be cleared. The help desk
  shows "Needs a reply" instead, from `admin_needs_reply()`.

## Manage User Accounts

Added 19 November 2026: `app/admin_users.php` (search, filter, sort, counts)
and `app/admin_user.php` (details, edit, activate/deactivate, export, delete).
Logic is in the "Manage User Accounts" block of `includes/admin.php`.

- **Team accounts are listed but never acted on.** Every write goes through
  `admin_user()`, which returns null for `is_admin`. Team access stays with
  `tools/make_admin.php`. No UPDATE may mention `is_admin`; the grep in
  `tests/admin_test.php` fails the suite.
- Deactivate stores `account_status = 'inactive'`; anything but `active` is
  shown as Deactivated and cannot sign in. `auth_user()` now also signs out a
  deactivated account on its next request. Before, only `auth_is_logged_in()`
  checked, and only with email verification on, so a live session survived.
- Delete and export reuse the existing actions and `auth_delete_account()`,
  with the typed-email check plus a `data-confirm` prompt from `eduflex.js`.
- Other learners' profile pictures are not shown, only initials:
  `avatar_show.php` serves the signed-in user's own picture and nothing else.
- **Never call a function that opens its own transaction from inside one in a
  check script without reading it first.** `admin_update_account()` used to
  roll back the caller's transaction, which turned a "rolled back" check
  against the real database into real writes. Fixed; one stray notification
  was deleted by id.

## Button motion

Added 20 November 2026: section 17 at the END of `eduflex.css` (it must stay
last, or older rules with their own `transition` override it) plus the
ripple and loading handlers at the foot of `eduflex.js`.

- App screens still never move a button on hover; only the landing page lifts.
- A submitting button gets `.is-loading`. It is NOT disabled, because a
  disabled button drops its own name/value from the POST, which would turn
  "Send and mark resolved" into "Send reply". A form that downloads a file and
  never leaves the page needs `data-no-loading`, or its spinner runs until the
  12-second fallback.
- `.ef-btn` is now `overflow: hidden`. Never put a badge that pokes out of a
  `.ef-btn`; use `.ef-icon-btn`, which has no clip (the bell relies on that).

## AI background on the sign-in pages

Added 20 November 2026 for `login.php`, `register.php` and `admin_login.php`
only (admin because it is the other half of the User/Admin switch on the same
card). Markup `partials/ai_background.php`, styles section 18 of
`eduflex.css`, canvas in `initAiBackground()` in `eduflex.js`, which returns at
once on any page without `.ef-ai-bg`. No library: Three.js would have to be
vendored under rule 3 and is far heavier than a 2D canvas needs.

- The card is untouched. Only `.ef-auth-page.has-ai-bg` loses its gradient.
- `index.php` has it too, in the hero only (`$aiBgVariant = 'landing'`,
  section 18b), contained in `.ef-hero-stage` rather than fixed, with no brain
  and a denser network. An IntersectionObserver stops the canvas once the
  hero is scrolled away. Do not make it fixed on the landing page: the long
  sections below would have particles behind tables and body text.
- To screenshot it headless, use `--force-prefers-reduced-motion`. With
  `--virtual-time-budget` alone Chrome may capture before the 1.4s fade-in
  and the canvas looks empty, which reads as a bug and is not one.

## Class names already taken by the companion

`.ef-thread`, `.ef-msg`, `.ef-msg-ai`, `.ef-msg-user`, `.ef-msg-avatar` and
`.ef-bubble*` belong to the AI companion's chat. On 19 November 2026 the
support conversation reused `.ef-thread` and `.ef-msg`, and its white box,
padding and `align-self: flex-start` leaked into the companion: every message
got a frame and the learner's questions moved left. Patrick saw it in a
screenshot the next day. The support side is now `.ef-conv` / `.ef-conv-msg`,
and `tests/admin_test.php` fails if the partial borrows the old names. Grep
the stylesheet for a class before inventing it.

The companion also has scroll buttons (section 20): "Back to top" and "Jump
to latest", centred at the bottom of the thread, each shown only when useful.

## Login animation

Added 20 November 2026. The login stays a normal server-side POST; nothing
about authentication moved to JavaScript.

- `auth_complete_login()` sets `$_SESSION['_login_welcome']`. It is the only
  place a sign-in becomes real (after password, emailed code and the active
  check), so a failed login can never see the success screen.
  `tests/auth_test.php` asserts the flag is set there and nowhere else.
- SHELL reads and clears it on the first app page and shows
  `partials/login_welcome.php` over it with `body.ef-entering`. Since
  24 November 2026 the team sees it too, with its own wording ("Opening the
  Admin Dashboard..."), chosen from `admin_is_admin()` in SHELL. A learner
  refused at the admin door is signed out by `admin_login.php`, which destroys
  the marker with the session. Both doors have "Authenticating..." and the
  error shake. Proved end to end with a throwaway account granted and revoked
  by `tools/make_admin.php`, never by signing in as `eduflex54@gmail.com`,
  which from an untrusted test client would email a code to the team inbox.
- **Signing out** (24 November 2026): `auth/logout.php` reads the role
  BEFORE `auth_logout()`, then sends everybody to the landing page, as
  Patrick asked: `index.php?signed_out=1` for a learner,
  `?signed_out=team` for the team. `index.php` shows a toast that leaves by
  itself after six seconds. The message is `partials/signed_out_notice.php`,
  fixed wording, never anything from the query string, and only the exact
  value `team` picks the team wording. eduflex.js strips the flag from the
  address bar once shown. A brief "Signing you out..." screen comes from
  `initSignOut()` on the sidebar link's `data-signout`; the href alone still
  signs out with no JavaScript. Section 21 of `eduflex.css`. (For a few hours
  each person went to their own sign-in page instead; both sign-in pages
  still understand the flag, harmlessly.)
- The sequence is pure CSS (section 19), about 2.3s, skippable by click, tap
  or Escape. eduflex.js only skips, counts up whole-number stat tiles, and
  removes the overlay and `ef-entering` at 3.7s.
- The sidebar entrance runs only above 860px; below that the sidebar is a
  transform-driven drawer and an entrance transform would fight it.
- Headless screenshots do not advance these CSS animations; use
  `--force-prefers-reduced-motion` to see the finished state.

Python 3.12 was installed for the user with winget on 19 November 2026, at
`%LOCALAPPDATA%\Programs\Python\Python312\python.exe`. Open a NEW terminal for
`python build_pages.py` to resolve to it rather than the Store alias.

## Deploying to a public host

Asked for on 27 November 2026. `DEPLOY.md` is the guide;
`php tools/deploy_check.php` is the readiness check. Nothing is deployed yet.

**Three decisions belong to Patrick and the adviser, not to the code**, and
they are section 1 of DEPLOY.md: participant data moving onto a company's
server that nobody consented to, the Gemini free tier's 20 requests a day
becoming shared with every visitor, and the laptop staying the demo machine
because it is the one that works with no wifi.

**What rules out most free tiers is outbound traffic, not PHP.** EduFlex needs
outbound HTTPS for `includes/ai.php` and outbound SMTP on port 587 for
PHPMailer. Vercel and Netlify cannot run PHP at all; Render and Railway have no
persistent disk on their free tiers, so uploaded material disappears on
restart. InfinityFree allows 587 and permits external APIs apart from a
blocklist, which is why DEPLOY.md recommends it.

**One file does not fit.** InfinityFree caps most files at 10 MB and
HTML/PHP/JS at 1 MB. Measured: every `.php` and `.js` is under 1 MB, and the
only file over 10 MB is `assets/video/eduflex-explainer.mp4` at 15.7 MB. Their
uploader can report success and delete it afterwards. Keep the original in the
repository either way: `tests/manuscript_test.php` asserts the file exists and
the laptop demo needs it.

Two things were fixed to make deployment safe, both worth keeping:

- **`config/database.php` is tracked by git**, so typing a live password into
  it publishes it. It now loads `config/database.local.php` first if present
  and guards each constant with `defined()`, so the override wins and a
  machine without that file keeps the XAMPP defaults. Nothing changed for
  teammates. `config/database.example.php` is the committed template, same
  pattern as `config/ai.php`.
- **`tools/` had no `.htaccess`.** Five of the six scripts refuse to run
  unless `PHP_SAPI` is `cli`, so they were not reachable, but the directory
  itself was open and a new script would be exposed the moment it landed.
  Denied as a directory now, like `config/`. Verified: 403 for all three
  checked.

`deploy_check.php` is the one tool meant to run in a browser, because shared
hosting has no shell. It needs `?run=1` and says to delete itself. It never
prints a key or a password, only whether one is set.

**A directory that answers 200 can still be incomplete, and `deploy_check.php`
used to miss that.** 28 November 2026: the landing page rendered on
`eduflex-infinity09` while `login.php` and `register.php` were both 500.
`includes/` had uploaded; `includes/verification.php` inside it had not.
`index.php` reaches three files in `includes/`. Any page with a sign-in form
loads `includes/auth.php`, which pulls in four more, `verification.php` among
them, so one absent file out of twenty-two took down every page that needs a
password and left the rest looking finished.

The check reported "15 boot files present" throughout, because its list was
hand-written and named only what `index.php` reaches. A hand-maintained list of
dependencies is a second copy of the require statements and goes stale the
first time one is added. **It is now derived**: it reads every PHP file that is
on the server, pulls out the literal `__DIR__` require targets and names any
that are missing, so a file is reported by whoever requires it.

**Tokenised with `token_get_all()`, not matched with a regex.** The regex
written first read the usage example in the docblock at the top of
`partials/public_footer.php` and reported that file as missing while it sat
right there. Comments and strings are whole tokens, so an example in a comment
is skipped. Only literal single-quoted paths count, which is why `mail.php`'s
deliberate fallback to `mail.example.php` is not flagged. Proved by dropping a
file carrying all three shapes into `partials/`, watching only the real one
fail, and deleting it again.

**A page can also be reached by URL rather than by require, and the first
version of the derived check was blind to those.** 28 November 2026, the same
day: `auth/` had not uploaded. It is one file, `logout.php`, and nothing in the
tree requires it; `partials/sidebar.php` and `app/settings.php` link to it. So
the site signed people in perfectly and answered 404 on Sign Out, leaving a
session nobody could end. Visiting `login.php` while still signed in then
redirects to the dashboard, by design, which Patrick reported as "Sign In goes
automatically to my profile". Both symptoms, one missing file.

`deploy_check.php` now takes the second route in too: every `href` and
`action` in the source that names a `.php` file. **Resolved against several
bases, not just the holding directory.** A partial is included from somewhere
else, so `partials/topbar.php`'s `href="materials.php"` means
`app/materials.php` and never `partials/`; resolving from the holding
directory alone reported eleven present files as missing. A link counts as
broken only when it resolves from none of root, `app/`, `app/actions/` or its
own directory. Proved by moving `auth/logout.php` aside, watching exactly the
two real callers fail, and moving it back.

**DEPLOY.md step 5 is now a positive list of what to upload.** A "skip these"
list was there first and it failed twice in two days, with `config/` and then
`auth/`: a directory on neither list is easy to pass over and nothing says it
is missing.

**InfinityFree's firewall refuses any URL containing the word "chat", and that
is why the companion answered "Could not reach the server".** 28 November 2026.
The page loaded, the material and topics listed correctly, and the answer never
came.

Measured rather than guessed, with a browser against paths that do not exist:

```
includes/zzznotreal.php           404   allowed through, just absent
includes/chat_zzznotreal.php      403   REFUSED
app/actions/zzznotreal.php        404   allowed through, just absent
app/actions/chat_zzznotreal.php   403   REFUSED
app/actions/zzznotreal_send.php   404   allowed through, just absent
```

A file that does not exist returning 403 instead of 404 proves the refusal is
on the URL, not the file. There is no setting for it on a free plan.

**`app/actions/chat_send.php` and `chat_clear.php` are now
`companion_send.php` and `companion_clear.php`.** Only URLs matter:
`includes/chat.php` is read from disk by PHP and never fetched over HTTP, so
its own 403 is cosmetic and the file, the functions and `chat_ask()` keep their
names. Renaming the library would have been a large change that fixed nothing.

The endpoint was invisible to `deploy_check.php` as well, because it is reached
by `$.post` and by nothing else: not required, not linked from an href. That is
the **third** route into a page, after require and href, and seven endpoints
use it. The check now reads `$.post`, `$.get`, `$.getJSON` and `fetch`
arguments too. Proved by moving `companion_send.php` aside and watching
`app/companion.php` report it.

**The lesson is wider than one word.** A deployment can refuse a URL that is
perfectly good, and the failure arrives as a client-side "could not reach the
server" with nothing in any log. When an AJAX feature works locally and not on
a host, request the endpoint directly and read the status before touching the
code.

**A 502 Bad Gateway on the public site is InfinityFree, not EduFlex.** It
comes from their `openresty` gateway rather than from PHP, so it appears on
static files as readily as on anything that runs code. Measured: forty
requests in quick succession returned 502 from the third onwards, including
for `includes/helpers.php`; eight seconds between requests and every one
answered 200, with nothing changed on the server in between. It is their rate
limit. Switching `config/ai.php` to a live provider does not cause it, because
no page calls a provider on load.

**The real win from deploying is the Android app**, and it needs no rebuild:
point the server address at the public URL and the cable, `adb reverse` and
the whole USB rehearsal disappear from the demo.

**Import `01_schema_shared_host.sql` on a shared host, not `01_schema.sql`.**
27 November 2026: Patrick imported the original into InfinityFree and got a
green "22 queries executed" banner AND a red `"DROP DATABASE" statements are
disabled` error at once. Both were true, which is the confusing part: every
table was created and only the first line failed. A shared host creates the one
database you may have and disables `DROP DATABASE`, `CREATE DATABASE` and `USE`.

The shared-host file is those three statements removed and nothing else. It is
**derived**, by `php tools/make_shared_schema.php`, because a hand-maintained
second copy would let a deployed site quietly end up a column behind a fresh
local install. `--check` compares without writing, and `tests/admin_test.php`
calls it, so drift fails the suite rather than surfacing on a server. Proved by
appending a table to `01_schema.sql` alone and watching two checks go red.

**`02_migrations.sql` is NOT imported after a fresh install.** Everything in it
is already in `01_schema.sql`. Verified by importing the shared-host file into
a throwaway database and running `tools/migrate.php`, which reported "Nothing to
do". The throwaway was dropped afterwards and Patrick's 4 accounts, 2 materials,
29 topics and 6 attempts were confirmed untouched; rule 16 applies to a probe
database as much as to anything else.

Worth knowing: `config/database.local.php` made that test easy. Pointing
`DB_NAME` at the throwaway ran the real `migrate.php` against it without
touching the development database, then the file was deleted.

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
