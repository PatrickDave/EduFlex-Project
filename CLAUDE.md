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
# import the schema (drops and recreates the eduflex database)
mysql -u root < database/01_schema.sql

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

1. **Never hand-edit `app/*.php`.** Those nine pages are generated. Edit the page
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
8. Prefer `Read`/`Edit` over shell redirection for file work.

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
app/              nine GENERATED pages, plus app/actions/*.php endpoints
partials/         sidebar.php and topbar.php, one copy for every page
database/         01_schema.sql and SCHEMA-NOTES.md
tests/            six PHP suites, two Playwright suites
build_pages.py    regenerates app/*.php from one shell template
```

`AI-OPTIONS.md` covers provider choice. `SETUP.md` is the install guide.
`README.md` section 4 documents the mastery model, 6b the practice loop, 6c the
companion.

## Test counts

358 PHP checks across six suites, all passing, plus 83 browser checks:

```
auth 40 | extract 32 | ai 63 | questions 73 | attempts 81 | chat 69
runner_browser 54 | chat_browser 29
```

Add tests with the rest of the work, not after. Several real bugs were caught
only because a test asserted something specific: a question containing a year
crashed retrieval, a repeated button press paid for a second set, the mock
leaked a passage tag into its answer.

## Schema deviations from Chapter III

Five additions, documented in `database/SCHEMA-NOTES.md`, all still needing to
be written into the manuscript:

1. `learning_activity.topic_progress_id`
2. `topic_progress.scored_items`
3. the whole `resource_chunk` table
4. seven extraction columns on `learning_resource`
5. the `bloom_level` reference table

Unresolved: `topic_progress` is keyed on `(user_id, resource_id, topic_name)`, so
one topic appearing in two uploads produces two separate mastery scores. The
`subscription` table still contradicts the non-commercial decision in Chapter I.

## Still to do

1. Notifications, support and settings. The last screens with placeholder
   behaviour.
2. Week 7: hardening and the AI-output validation rubric run. That needs a real
   API key and produces Chapter IV numbers.
3. Patrick has still not run topic detection, generation or chat against a real
   provider. The live HTTP call is the one untested path in the system.

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

## Working with Patrick

- Conversation can be informal. Anything written for the manuscript must be
  formal academic prose.
- Prefers numbered and bulleted structure over dense paragraphs.
- Prefers complete, ready-to-run files over snippets.
- No em dashes.
- Say plainly when something is not done or not tested. He is presenting this
  work to a panel and needs to know exactly what he can and cannot claim.
