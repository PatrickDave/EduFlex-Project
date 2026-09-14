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
    settings.php           Profile, security, study goal, notifications
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
  partials/
    sidebar.php            Sidebar markup, one copy for every page
    topbar.php             Header markup, one copy for every page
  database/
    01_schema.sql          The full schema. Import this into phpMyAdmin.
    SCHEMA-NOTES.md        Three columns added beyond Chapter III, and why
  tests/
    auth_test.php          40 checks
    extract_test.php       32 checks
    ai_test.php            63 checks
    questions_test.php     73 checks
    attempts_test.php      81 checks, including the mastery arithmetic
    chat_test.php          69 checks, including the grounding rules
    runner_browser_test.py 54 checks against a running system (Playwright)
    chat_browser_test.py   29 checks against a running system (Playwright)
  assets/
    css/eduflex.css        The whole design system. One file.
    js/eduflex.js          Drawer, mastery rendering, bar charts
    vendor/                Bootstrap 5.3.3, jQuery 3.7.1 and Inter, all local.
                           The system makes no external network request at all,
                           so it renders identically in a room with no wifi.
  build_pages.py           Regenerates the 9 app pages from one template
```

## 1b. Security decisions already made

Worth knowing, because a panel may ask:

- Passwords are hashed with `password_hash()` using bcrypt. Nothing stores or
  logs a plain password. `password_needs_rehash` upgrades old hashes on login.
- Every query uses a prepared statement. No SQL is built by concatenation.
- Login gives the same message for a wrong password and an unknown email, so
  nobody can use the form to discover which emails are registered.
- `session_regenerate_id(true)` runs on login, which defeats session fixation.
- Session cookies are `HttpOnly` and `SameSite=Lax`.
- Every form carries a CSRF token, checked with `hash_equals`.
- All output goes through `e()`, which escapes for HTML.

Two things still to do before this leaves a local machine: set
`'secure' => true` on the session cookie once you have HTTPS, and add rate
limiting on the login form.

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

The nine app pages share one shell. Edit `SHELL` or a page body inside
`build_pages.py`, then:

    python3 build_pages.py

This rewrites everything in `app/`. Do not hand-edit those files while the
script still exists, or the next run will overwrite you.
