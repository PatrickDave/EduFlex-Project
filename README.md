# EduFlex

Front end plus working accounts. Every screen from the Chapter III storyboard,
with real registration, login and session handling on top.

**Start with `SETUP.md`.** It walks through XAMPP and the database in order.

No build step, no npm, no internet needed. Bootstrap and jQuery are vendored
in `assets/vendor/`.

---

## 1. What is here

```
eduflex-ui/
  SETUP.md                 Install and first run. Read this first.
  index.php                Landing page (with the AI assistant illustration)
  login.php                Log in. Handles GET and POST.
  register.php             Create account. Handles GET and POST.
  privacy.php              Privacy Policy and participant information sheet.
                           Public: it must be readable before registering.
  terms.php                Terms of Service. Public, same reason.
  auth/
    logout.php             Destroys the session
  app/                     Every page here requires a login
    dashboard.php          Adaptive analysis + topic mastery + weekly activity
    companion.php          AI chat grounded in an uploaded resource
    materials.php          Upload, library, analysis status (kept, now folded
                           into the companion for learners)
    practice.php           Generate sets, list stored sets, start an attempt
    practice_run.php       The question runner and, once scored, the result
    growth.php             Mastery over time, Bloom level per topic
    scores.php             Overall mastery ring, mastery by topic
    history.php            Completed activities with resulting bands
    settings.php           Profile picture, personal details, change password,
                           export my data, delete my account
    notifications.php      What EduFlex did with your material. Read only:
                           nothing on this page writes a notification.
    support.php            Send a request, see your own requests, static FAQ
    actions/               POST endpoints. Every state change goes through one.
  config/
    database.php           PDO connection. Edit credentials here.
  includes/
    auth.php               Register, login, logout, session guard
    helpers.php            Escaping, CSRF, flash, mastery bands, Bloom weights
    ai.php                 Provider abstraction: OpenAI-compatible, Gemini, mock
    topics.php             Topic detection from uploaded material
    questions.php          Question generation and the output validator
    attempts.php           Attempts, scoring, the mastery write, recommendation
    chat.php               The companion: retrieval, grounding, citations
    resources.php          Upload, processing, listing, deletion
    extract.php            PDF and DOCX text extraction, chunking
    stats.php              Every read model the screens use
    notifications.php      Writing and reading notifications, and the rule
                           that a notification never breaks its own event
    support.php            Support requests: the fixed type list, validation
    security.php           Response headers, error output, login throttling
    avatar.php             Profile pictures: validation, storage, initials
    rubric.php             The arithmetic and Markdown behind the rubric run
    legal.php              The study facts privacy.php and terms.php need, the
                           Who to Contact block, and the banner shown while any
                           value is unfilled
    manuscript.php         The seven modules and the reference list, taken from
                           the manuscript, rendered on the landing page
    subscription.php       Plans, the free and premium tiers, and the one
                           sentence every screen uses about cost
    exams.php              Mock examinations: topic choice, assembly from
                           stored questions, per-topic breakdown
  partials/
    sidebar.php            Sidebar markup, one copy for every page
    topbar.php             Header markup, one copy for every page
    public_footer.php      Footer for the five public pages, so the legal
                           links are defined once rather than five times
  database/
    01_schema.sql          The full schema. FIRST INSTALL ONLY: it begins with
                           DROP DATABASE.
    02_migrations.sql      The same changes as safe, re-runnable ALTERs, for a
                           database that already has data in it.
    SCHEMA-NOTES.md        Eight deviations from Chapter III, and why each one
  tests/
    auth_test.php          40 checks
    extract_test.php       44 checks (5 of them need the PHP zip extension;
                           without it the DOCX block reports SKIP and the
                           suite reports 39). Includes the garbled-PDF
                           detector and the cases it must not accuse.
    ai_test.php            63 checks
    questions_test.php     73 checks
    attempts_test.php      81 checks, including the mastery arithmetic
    chat_test.php          69 checks, including the grounding rules
    notifications_test.php 77 checks, including that a failed notification
                           never breaks the event that caused it
    support_test.php       39 checks, including that request_type cannot come
                           from the POST body
    settings_test.php      60 checks, including that deleting an account
                           removes every row for that learner and no other
    security_test.php      79 checks: the open-redirect table, the response
                           headers, login throttling, session expiry
    avatar_test.php        87 checks, including two polyglot files that
                           getimagesize() alone accepts as valid PNGs
    rubric_test.php        69 checks on the Chapter IV arithmetic, including
                           the cases a mock run can never produce
    legal_test.php         76 checks that the consent documents are public,
                           linked everywhere, and that the adviser's personal
                           number is not published
    manuscript_test.php    39 checks that every nav link resolves and that no
                           unbuilt module is advertised
    subscription_test.php  48 checks, including that no plan offers a purchase
    exams_test.php         46 checks, including that a fully stocked exam makes
                           zero provider calls
    runner_browser_test.py 54 checks against a running system (Playwright)
    chat_browser_test.py   29 checks against a running system (Playwright)
  assets/
    css/eduflex.css        The whole design system. One file.
    js/eduflex.js          Drawer, mastery rendering, bar charts
    vendor/                Bootstrap 5.3.3, jQuery 3.7.1 and Inter, all local.
                           The system makes no external network request at all,
                           so it renders identically in a room with no wifi.
  tools/
    rubric_run.php         Produces the Chapter IV validator figures. The only
                           script here that spends provider quota.
    migrate.php            Applies schema changes to an existing database
                           without dropping anything. Run it, never 01_schema.
  build_pages.py           Regenerates the 11 app pages from one template
```

## 1b. Security decisions already made

Worth knowing, because a panel may ask. `includes/security.php` holds the
response-header, error-output and throttling controls; `tests/security_test.php`
asserts all of them.

**Credentials and sessions**

- Passwords are hashed with `password_hash()` using bcrypt. Nothing stores or
  logs a plain password. `password_needs_rehash` upgrades old hashes on login.
- One password rule, `auth_password_error()`, shared by registration and the
  change-password form. Changing a password verifies the current one first and
  regenerates the session ID.
- Login gives the same message for a wrong password and an unknown email, so
  nobody can use the form to discover which emails are registered.
- `session_regenerate_id(true)` runs on login, which defeats session fixation.
- Session cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` whenever the
  request arrives over HTTPS. That is detected from the server's own variables,
  never from a request header, and never hard-coded: sending `Secure` over plain
  HTTP makes the browser discard the cookie so nobody can sign in at all.
- Sessions expire two ways: two hours idle, and twelve hours absolute. The
  absolute limit is the one that matters, because a stolen cookie that is used
  constantly never goes idle.
- **Login is rate limited.** Five failures against one email from one address
  within fifteen minutes refuses that pair; twenty failures from one address
  across any emails refuses the address. Failures against emails that do not
  exist are counted too, so the throttle cannot be used as an
  account-existence oracle. A successful login clears the counter. See
  `login_attempt` in `database/SCHEMA-NOTES.md`.

**Input and output**

- Every query uses a prepared statement. No SQL is built by concatenation.
- Every form carries a CSRF token, checked with `hash_equals`. Twenty of the
  twenty-one endpoints in `app/actions/` check it and refuse anything that is
  not a POST. The exception is `avatar_show.php`, which is the `src` of an
  `<img>`: it is a GET, it changes nothing, and it serves only the session's own
  picture, so there is no state for a token to protect and no id to tamper with.
- All output goes through `e()`, which escapes for HTML.
- Uploads are checked by extension **and** by leading bytes, stored under a
  generated name, and `storage/.htaccess` denies Apache from serving or
  executing anything in that directory.
- The login form's `next` destination is validated by
  `auth_safe_redirect_target()`, which accepts only same-site paths. The check
  this replaced was bypassable and is described in section 1c.

**Response headers**, sent from `auth_boot()` so no page can forget them:

| Header | Value | Why |
|---|---|---|
| `Content-Security-Policy` | `default-src 'self'` and friends | The browser refuses any external origin, so "no external network requests" is enforced rather than merely agreed |
| `X-Content-Type-Options` | `nosniff` | An uploaded file cannot be sniffed into something executable |
| `X-Frame-Options` | `DENY` | Clickjacking. The WebView loads pages top-level, so this costs nothing |
| `Referrer-Policy` | `same-origin` | The page a learner came from does not leak off-site |
| `Permissions-Policy` | camera, mic, geolocation off | None of them is used |
| `Strict-Transport-Security` | 1 year, **HTTPS only** | Harmful on plain HTTP, so it is conditional |
| `X-Powered-By` | removed | XAMPP announced `PHP/8.2.12`, which tells an attacker which exploits to try |

The CSP grants `'unsafe-inline'` for scripts and styles, because the generated
pages carry inline `<script>` blocks and inline `style` attributes. Say that out
loud if asked: the policy blocks external code, not injected inline code.
Escaping through `e()` is what stops injected inline code.

**Error output.** `includes/*.php` never show a database error to a user, because
the message leaks table and column names. XAMPP ships with `display_errors` on,
which undid that care, so `security_harden_error_output()` turns it off and logs
instead. The CLI is exempt so the test suites still show their output, and
defining `EDUFLEX_DEBUG` as true restores the old behaviour while developing.

**Still to do before this leaves a local machine:** serve it over HTTPS, at
which point the `Secure` cookie flag and HSTS turn themselves on.

## 1c. The open redirect, and how it was found

Worth recording, because it is the one exploitable defect found in the
hardening pass and a panel will respect the account of it.

The login form accepts a `next` parameter so a learner who is bounced to the
login screen returns where they were going. It used to be validated inline with:

    !preg_match('#^(https?:)?//#', $candidate)

That rejects `//evil.example.com` and `https://evil.example.com`. It does not
reject `/\evil.example.com`, and browsers read a slash followed by a backslash
the same as two slashes. Posted against the running system, the server answered:

    HTTP/1.1 302 Found
    Location: /\evil.example.com/phish

So a learner could sign in legitimately, with correct credentials, and land on
somebody else's site, which is exactly the setup for a convincing credential
phish.

The fix is `auth_safe_redirect_target()` in `includes/auth.php`. It does not add
another pattern to the blocklist, because that game is unwinnable one character
at a time. It accepts only what it recognises as a same-site path and refuses
everything else: any scheme, `//`, any backslash, any percent-encoded slash or
backslash, any control character, leading whitespace, and `..`. All twenty-three
cases are in `tests/security_test.php`, including the one that was live.

## 2. The mobile app is not a separate build

The manuscript specifies an Android WebView wrapper. That means the nine mobile
storyboard frames are these same pages at a narrow width, not a second set of
screens. Everything is responsive already:

| Width          | Behaviour                                                  |
|----------------|------------------------------------------------------------|
| above 1200px   | Full layout with the right context rail                    |
| 992 to 1200px  | Rail hides, hero stacks                                    |
| below 860px    | Sidebar becomes a slide-in drawer behind the hamburger      |
| below 520px    | Buttons go full width, stat tiles drop to two columns       |

Point the WebView at `http://<your-server>/eduflex/` and the mobile storyboard
is what loads. Do not fork the markup.

## 3. Design tokens

`assets/css/eduflex.css` section 1 holds every value. They match the Figma
variable collection "EduFlex Tokens" in file `A4ZVR459ciHA8nFjNTuyU8` exactly.

| Token                 | Value     | Used for                          |
|-----------------------|-----------|-----------------------------------|
| `--ef-primary`        | `#0EA5E9` | Primary actions, active nav       |
| `--ef-primary-hover`  | `#0284C7` | Hover state                       |
| `--ef-primary-bright` | `#38BDF8` | Logo, secondary bars              |
| `--ef-cyan`           | `#22D3EE` | Robot eyes, accents               |
| `--ef-deep`           | `#075985` | Bloom tag text                    |
| `--ef-mastered`       | `#10B981` | Mastery >= 85                     |
| `--ef-developing`     | `#F59E0B` | Mastery 70 to 84                  |
| `--ef-weak`           | `#F43F5E` | Mastery below 70                  |
| `--ef-empty`          | `#EDF3F7` | Under 5 scored items, no value    |
| `--ef-surface-sky`    | `#E8F4FD` | Soft panels, active nav backdrop  |
| `--ef-ink`            | `#0B2434` | Robot face screen                 |

Never hardcode a colour in a page. If a value needs to change, change the token.

## 4. The mastery model

This is the part Chapter III currently does not define, and the panel will ask.
The UI here implements it, so the manuscript and the system can finally agree.

For a topic `t`, over that topic's scored items:

```
M = ( SUM(w_i * c_i) / SUM(w_i) ) * 100

  c_i = 1 if the answer was correct, else 0
  w_i = bloom_weight * recency_weight

  bloom_weight:   Remember 1.0   Understand 1.2   Apply 1.5
                  Analyze  1.8   Evaluate   2.0   Create 2.2

  recency_weight: 1 / (1 + 0.15k)    k = how many attempts ago
```

Rules:

- Fewer than 5 scored items shows "No data", never a percentage. This is the
  cold start, and it is designed rather than hidden.
- Bands: `>= 85` mastered, `70 to 84` developing, below `70` weak.
- Mastered requires 85 or above sustained across two separate sessions.
- Bloom level steps up when `M >= 85` at the current level, steps down below
  `60`, otherwise holds.
- The next recommended activity is the lowest-M topic with at least 5 items,
  generated at that topic's current Bloom level.

The thresholds live in two places and must stay identical:
`assets/js/eduflex.js` (`MIN_ITEMS`, `masteryBand`) and your PHP calculation.
If they drift, the interface will disagree with the database.

## 5. Rendering data

Mastery rows and bar charts are driven by data attributes, so PHP only has to
echo numbers. No markup logic in the template.

```html
<div class="ef-mastery-row"
     data-topic="Fourier Transforms"
     data-mastery="58"
     data-items="12"></div>

<!-- leave data-mastery empty when the topic is under the item minimum -->
<div class="ef-mastery-row" data-topic="Data Structures"
     data-mastery="" data-items="3"></div>
```

```html
<div class="ef-bars" data-max="5">
  <div class="ef-bar-col" data-value="2"><span class="ef-bar"></span><span class="ef-bar-label">M</span></div>
</div>
```

If you inject rows after page load, call `EduFlex.refresh()`.

## 6. Wiring a page to real data

Right now the app pages carry sample values. Replacing them is the same move
every time: query, then echo into the data attributes.

```php
<?php
$stmt = db()->prepare(
    'SELECT topic_name, mastery_score, scored_items
       FROM topic_progress
      WHERE user_id = ?
      ORDER BY mastery_score ASC'
);
$stmt->execute([$user['user_id']]);
$topics = $stmt->fetchAll();
?>

<div class="ef-mastery">
<?php foreach ($topics as $t): ?>
  <?= mastery_row_html(
        $t['topic_name'],
        $t['scored_items'] >= MASTERY_MIN_ITEMS ? (float) $t['mastery_score'] : null,
        (int) $t['scored_items']
      ) ?>
<?php endforeach; ?>
</div>
```

`mastery_row_html()` is in `includes/helpers.php`. It emits exactly the markup
`assets/js/eduflex.js` expects, and applies the item minimum for you, so the
band logic cannot drift between the two.

Rules to hold to:

- Never echo a database value without `e()`.
- Never build SQL with string concatenation. Always bind parameters.
- Keep `MASTERY_MIN_ITEMS` in `includes/helpers.php` equal to `MIN_ITEMS` in
  `assets/js/eduflex.js`.

## 6b. How one practice attempt runs

This is the loop the study claims, end to end. Every step is server side.

1. Practice posts an activity to `app/actions/attempt_start.php`.
   `attempt_start()` checks the set belongs to the learner, then either opens a
   new row in `activity_attempt` or resumes the open one. A refreshed page or a
   closed tab never creates a second attempt or loses recorded answers.
2. `app/practice_run.php` renders the questions with `attempt_load()`, which
   deliberately does not select `correct_answer`. Reading the page source
   during an attempt tells a learner nothing.
3. Each choice posts to `app/actions/attempt_answer.php`. `attempt_answer()`
   compares the submission against the stored answer in PHP, refuses a second
   answer to the same item, refuses an item from another set, and treats a skip
   as an unanswered item that still counts. The correct answer travels back only
   in that reply, once the learner has committed.
4. `app/actions/attempt_finish.php` scores the attempt as
   `correct / total_items`, so unanswered questions count against it, then calls
   `mastery_recalculate()` inside the same transaction.
5. `mastery_recalculate()` recomputes the topic from its entire answer history
   rather than adjusting the previous figure, so the number can never drift.
   `recommendation_refresh()` then runs outside the transaction, because a
   failure to suggest the next topic must not undo a recorded result.

The arithmetic in steps 4 and 5 involves no model call. It is deterministic,
costs nothing, and can be recomputed by hand in front of a panel.

## 6c. How the companion answers

The system is named after this feature, so the rules behind it are worth
stating plainly. `includes/chat.php`.

1. Retrieval runs first. `chat_select_context()` scores every chunk of every
   processed document the learner owns against the words in their question, and
   returns the best four. The search is scoped by `user_id` in SQL, so one
   learner's question can never reach another learner's material.
2. Only those passages are put in front of the model, inside a `<passages>`
   block, with the instruction to answer from them and from nothing else.
3. When the question contains content words that appear in no passage, the
   prompt says so explicitly and tells the model to report that the material
   does not cover it. A question with no content words at all ("summarise
   this") is not treated the same way, because it has nothing to match on by
   its nature.
4. The reply names the passages it used. `chat_parse_reply()` maps those back
   to real documents and silently discards a citation to a passage the model was
   never given, so an invented source cannot reach the screen.
5. An answer the model could not ground is labelled as such in the interface
   rather than hidden. Saying "your material does not cover this" out loud is
   the behaviour the design is for.

Conversation history is replayed so follow-up questions work, trimmed to a
character budget so a long conversation cannot quietly grow the cost of every
later message. The learner can clear their own conversation; the system's own
audit trail of detection and generation calls is separate and stays.

## 6d. What notifications say, and when

`includes/notifications.php`. Nothing here calls a model, so notifications cost
nothing. Two rules govern every write, and both matter more than the feature:

1. **A notification never breaks the event that caused it.** `notify()` catches
   everything and logs. If a learner answers eight questions and the
   notification insert fails, the score is still recorded. Asserted in
   `tests/notifications_test.php` by dropping the table and re-running each
   event.
2. **Nothing is written by a page render**, only by the action that caused the
   event. A write on render would add a row every time anybody refreshed, and
   the unread count would climb on its own.

Four events write a notification, each from inside the function that already
performs it:

| Event | Written by | Type |
|---|---|---|
| A document finished being read and chunked | `resource_process()` | `material_ready` |
| Topic detection added topics | `topics_detect()` | `topics_found` |
| A topic crossed into the mastered band | `mastery_recalculate()` | `topic_mastered` |
| The recommended topic changed | `recommendation_refresh()` | `recommendation` |

Three of those fire on a change rather than on every call, because the last two
functions run after every finished attempt:

- `topic_mastered` compares the band it is about to write against
  `topic_progress.weakness_priority`, which holds the band written last time.
  Only the transition into `mastered` notifies. A topic that falls out of the
  band and climbs back notifies again, which is correct: it is news twice.
- `recommendation` compares the new target against the topic of the previous
  live recommendation. A fresh recommendation row is still written every time,
  as before; only a changed target notifies.
- `topics_found` fires only when detection actually inserted something, so
  re-running it on an already-detected document is silent.

There is no notification-preferences table and no toggles in Settings. Rather
than ship a switch that changes nothing, there is no switch. All four events are
always recorded, and nothing is emailed. If the storyboard toggles are wanted,
they belong in Chapter V as future work.

## 6e. Withdrawing: export and delete

Chapter III promises a participant can withdraw and take their data with them.
Settings implements both, and neither needs the project team:

- **Export my data** posts to `app/actions/export_data.php` and downloads one
  JSON file: account, materials, topics with their mastery scores, attempts, and
  every answer with whether it was marked correct. The password hash is
  deliberately absent, and the uploaded files are not included because the
  learner already holds those.
- **Delete my account** requires the learner to type their own email address.
  One `DELETE` on `user` cascades through every table; the uploaded files are
  unlinked from disk first, while there is still a row pointing at them.
  `tests/settings_test.php` proves it removes every row for that learner and not
  one row belonging to anybody else.

## 6f. Profile pictures

`includes/avatar.php`. A learner uploads one image; a learner who has not gets
their initials. It is the only place in EduFlex where a learner's own file is
handed back to a browser, which is why it is treated carefully.

- **The type is decided by reading the file**, never from its name. The
  extension written to disk comes from the detected type, so `shell.php`
  containing a real PNG is stored as a `.png`, and `portrait.png` containing PHP
  is refused.
- **`getimagesize()` is not enough on its own**, and this is worth knowing.
  Given a file that opens with the 8-byte PNG signature and continues with
  arbitrary bytes, it reports a perfectly good `image/png` and reads the
  "dimensions" out of whatever followed. A PHP script with a PNG signature glued
  to the front came back as `image/png`, 1752113186 by 1885436268 pixels. So
  `avatar_header_is_intact()` parses the header properly as well: for a PNG that
  means verifying the IHDR chunk's own CRC32, which no payload satisfies by
  accident. `tests/avatar_test.php` includes a polyglot with a plausible 64 by 64
  size, so it is the CRC doing the work and not the dimension bounds.
- **The file is never served by Apache.** It lands in `storage/avatars/`, which
  `storage/.htaccess` denies, and reaches a page only through
  `app/actions/avatar_show.php`, which streams it with a Content-Type taken from
  the detected type plus `nosniff`.
- **That endpoint takes no user id.** It serves the session's own picture, so
  there is no parameter to tamper with and no ownership check to get wrong.
  Nothing in EduFlex shows one learner another learner's picture.
- Replacing a picture deletes the old file, and deleting an account deletes the
  picture along with everything else.

Not re-encoded through GD, which would be the stronger treatment because it
strips anything hiding alongside the image data. The GD extension is not enabled
on the development machine, and shipping an untested branch that would start
running the moment somebody enabled it is the worse trade. Recorded as optional
future hardening in `docs/week7-hardening.md`.

## 6g. Measuring what the validator discards

`tools/rubric_run.php`. This is the only script in the repository that costs
money, and the only thing that produces the Chapter IV figures on AI output
quality.

```
php tools/rubric_run.php --sets=12 --out=docs/rubric-run.md
```

It generates N practice sets from material already uploaded, and reports how
many questions the model returned, how many `questions_validate()` discarded,
the count for each reason, and how many whole sets fell below the four-usable
floor. Output is Markdown, ready to paste into the manuscript.

Four things about it are deliberate:

1. **It refuses to run against the mock provider.** The mock returns questions
   written to pass validation, so a mock run reports a 0 percent rejection rate.
   That number would be a measurement of the mock, not of a language model, and
   putting it in Chapter IV would be a false claim. `--allow-mock` exists to
   check the harness itself, and stamps a warning across the report.
2. **It asks before spending.** Each set is one provider call. A mistyped
   `--sets=120` is 120 calls, so it prints the plan and waits for a `y`.
3. **A failed provider call is not a validation result.** A set the model never
   returned says nothing about the quality of its questions, so it is counted
   separately and excluded from every rate. Counting failures as sets with zero
   rejections would make the model look better the more often it broke.
4. **The arithmetic is not in the script.** `rubric_summarise()` and
   `rubric_report()` live in `includes/rubric.php` and are pure, so
   `tests/rubric_test.php` asserts them without a provider. A percentage in a
   manuscript should come from arithmetic somebody has checked, not from a
   one-off script that cannot be run twice the same way.

Nothing is thrown away: the sets a rubric run generates are stored like any
other, so the learner ends up with real practice material.

## 6h. Mock examinations

`includes/exams.php`. Chapter III defines this as creating "practice
examinations ... to help learners assess their understanding and prepare for
academic assessments", so it covers ground rather than drilling one topic:
20 questions across up to three topics, weakest first.

**It assembles before it generates, and that is the whole design.** Generation is
one provider call per set of eight, so writing a 20-question exam from scratch
would cost three calls every single time. Instead it takes questions the learner
has not been asked from sets that already exist and calls the provider only for
the shortfall. A learner who has practised for a while gets an exam instantly and
for nothing, and the flash message says which it was.

**A question already asked never comes back.** The exclusion matches on question
text rather than item id, because building an exam copies the chosen questions
into the exam's own activity. Answering the copy leaves the original untouched,
so an id-based check served the same question again next time. The test suite
caught that before it shipped.

**Exam answers count toward mastery exactly like practice answers**, at the same
Bloom and recency weighting, for every topic the exam touched. That is what
`activity_item.topic_progress_id` and `activity_item.bloom_level` are for: the
exam activity belongs to no single topic, so each item says which topic it is
evidence for. A practice set leaves both NULL and is entirely unaffected.

The result screen shows the per-topic breakdown, because one number from a
20-question exam hides the thing the learner most needs to know.

## 7. What was deliberately left out

These appear in the original storyboard but are not in the 34-module program
specification, so they are not built. Each is a scope decision, not an oversight:

- Google OAuth sign-in
- Voice input on the chat composer
- Video, image, link and PPTX ingest (PDF and DOCX only)
- Badges, tiers, streaks, and the pricing page
- "Top 5% Globally", class averages, and any peer comparison, which need a user
  base the study will not have
- Undefined metrics: Engagement Score, Retention, Intelligence Score,
  Improvement Velocity, Recall Speed

If you want any of them back, they are additive. Say which and it goes in.

## 8. Regenerating the app pages

The eleven app pages share one shell. Edit `SHELL` or a page body inside
`build_pages.py`, then:

    python3 build_pages.py

This rewrites everything in `app/`. Do not hand-edit those files while the
script still exists, or the next run will overwrite you.
