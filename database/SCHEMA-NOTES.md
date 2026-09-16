# Schema notes

`01_schema.sql` follows the Chapter III data dictionary (Tables 4 to 15). Eight
things differ, and Chapter III should be amended to match before your final
defense. Each one is a gap in the documented design, not a preference.

## 1. `learning_activity.topic_progress_id` — ADDED

Your data dictionary links a learning activity only to a `resource_id`. There
is no column anywhere that says which **topic** an activity is about.

That breaks the core loop of the study. To compute per-topic mastery you have
to trace a scored answer back to a topic:

    attempt_response -> activity_item -> learning_activity -> ???

Without this column the chain stops at the resource. One PDF can produce nine
topics, so "which topic did the learner just get wrong" becomes unanswerable,
and adaptive recommendation cannot work.

**Chapter III change:** add `topic_progress_id INT(11), FK, nullable` to Table
10 (Learning Activity), referencing `TOPIC_PROGRESS.topic_progress_id`.

## 2. `topic_progress.scored_items` — ADDED

The mastery model shows "No data" until a topic has at least 5 scored items.
Your documented `TOPIC_PROGRESS` stores `mastery_score` but not how many items
produced it, so every screen would need a `COUNT` join against
`attempt_response` just to decide whether to show a number.

Keep this counter in step with `attempt_response` inside the same transaction
that recalculates `mastery_score`.

**Chapter III change:** add `scored_items INT(11), NOT NULL, default 0` to
Table 12 (Topic Progress).

## 3. `resource_chunk` table — ADDED

Extraction turns an upload into plain text, which is then split into
overlapping chunks of about 700 words. Chunks are the unit sent to the language
model: a 40-page reviewer will not fit in one request, and sending a whole
document with every chat message would be slow and, on a metered provider,
expensive.

Your ERD has no table for this, so there is nowhere to put the extracted text.

**Chapter III change:** add RESOURCE_CHUNK as a new entity, one-to-many from
LEARNING_RESOURCE, with `chunk_id` PK, `resource_id` FK, `chunk_index`,
`content`, `word_count`, `created_at`.

## 4. Extraction bookkeeping on `learning_resource` — ADDED

Seven columns: `original_name`, `file_size`, `char_count`, `chunk_count`,
`extract_engine`, `extract_message`, `processed_at`.

Your Table 6 stores `processing_status` but nothing about the outcome. Without
these the interface cannot tell a learner why a file failed, how much text was
read, or when it was processed.

**Chapter III change:** add these seven attributes to Table 6.

## 5. `bloom_level` reference table — ADDED

Not in your ERD. It stores the six Bloom levels and their weights so the
mastery formula has one authoritative source, and so you can cite the weights
in Chapter III without digging through code.

This is optional. If your panel objects to a table outside the ERD, move the
weights into a PHP constant instead. Do not scatter them across both.

## 6. `login_attempt` table — ADDED

Not in your ERD. One row per **failed** sign-in attempt, holding the email tried,
the address it came from, and when.

Without it the login form accepts unlimited password guesses at whatever rate
the network allows, which is the only thing standing between a weak password and
an account. `includes/security.php` refuses an email and address pair after
five failures in fifteen minutes, and refuses an address entirely after twenty
failures across any emails, which is what stops one attacker spraying a single
common password across many accounts.

Three design points a panelist may ask about, all deliberate:

1. **No foreign key to `user`.** Attempts against an email that does not exist
   must be counted too. If they were not, the throttle would itself answer the
   question "is this email registered?": unlimited guesses at an unknown
   address, five at a real one.
2. **Only failures are stored, and a successful login deletes the rows for that
   email and address.** A learner who mistypes four times and then gets it right
   is not punished, and the table holds no record of anybody's successful
   sign-ins, which would be a log of when each participant was studying.
3. **Pruned on each successful login**, not by a scheduled job, because this
   project has no scheduler. Rows outside the window are deleted then, so the
   table stays small on its own.

If your panel objects to a table outside the ERD, the alternative is no rate
limiting, and that is a worse answer. The table is cheap to describe: three
columns and two indexes.

## 7. `user.avatar_path` -- ADDED

One nullable VARCHAR(255) on `user`, holding the path to that learner's profile
picture relative to the project root, or NULL when they have not set one.

The storyboard's profile screen shows an avatar, so the screen was already
drawing one; until now it was a coloured circle that never changed. A learner
can now upload a real picture, and a learner who has not gets their initials.

Two questions a panelist might ask:

1. **Why a column rather than a filename derived from the user id?** Because
   `01_schema.sql` starts with `DROP DATABASE`, so ids restart at 1 on every
   reimport while old files stay on disk. A derived name would hand the next
   user 1 the previous user 1's photograph. The stored path carries 12 random
   bytes, so it cannot collide across reimports.
2. **Why is the file not in the web root?** It goes to `storage/avatars/`, which
   `storage/.htaccess` denies Apache from serving or executing, and reaches a
   page only through `app/actions/avatar_show.php`. That endpoint takes no user
   id at all: it serves the session's own picture, so there is no parameter for
   anybody to change. See README section 6f.

## 8. `activity_item.topic_progress_id` and `activity_item.bloom_level` -- ADDED

Two nullable columns on `activity_item`, added when Generate Mock Examinations
was built.

Until then an item's topic and Bloom level came from its activity, because a
practice set is one topic at one level. A mock examination is neither: it spans
several topics and several levels by design, so each item has to say which topic
it is evidence for and how hard it was.

Everything that reads them uses the item's value and falls back to the
activity's. A practice set leaves both NULL and behaves exactly as before, which
is why adding these changed no existing mastery figure. `tests/attempts_test.php`
passed unaltered across the change, and that is the evidence.

One implementation note worth keeping, because it cost an hour. The natural way
to write the fallback is `COALESCE(ai.topic_progress_id, la.topic_progress_id) = ?`.
Do not. SQLite takes type affinity from the column on the left of a comparison,
and `COALESCE(...)` is an expression with no affinity, so the bound parameter
stays TEXT `'1'` and never matches INTEGER `1`. Every mastery score silently
became zero. MySQL coerces the two and hides it. `mastery_recalculate()` compares
each column to the parameter separately for that reason.

---

# Design limitations worth knowing

These are faithful to your ERD. They are not bugs, but they will shape what the
system can do, and a panelist may notice.

## Topics are scoped to a single resource

`topic_progress` has a `UNIQUE (user_id, resource_id, topic_name)` constraint,
which follows your ERD. The consequence: if "Fourier Transforms" appears in two
different uploaded PDFs, the learner gets **two separate mastery scores** for
what is really one topic.

Options if you want one score per topic:

1. Drop `resource_id` from `topic_progress` and make the key
   `(user_id, topic_name)`. Simplest, and it matches how learners think.
2. Add a `topic` table and let many resources map to one topic. Cleaner, more
   work.

Decide before you write the analysis code. Changing it later means migrating
data.

## Subscription contradicts the non-commercial decision

Chapter I describes EduFlex as a free, non-commercial prototype, and you
removed the revenue model in favour of a sustainability section. The ERD still
carries a `SUBSCRIPTION` table with `plan_type` and `expires_at`.

The schema creates every account with `plan_type = 'free'`, so nothing breaks.
But you should decide which is right and make the manuscript agree with itself.
A panelist reading both will ask.

## Attempts do not record which Bloom level was answered

`activity_item` has no `bloom_level`; it lives on `learning_activity`. That is
fine as long as every item in one activity shares a level, which is how the
generator is specified. If you later mix levels inside one activity, the
mastery weighting will silently use the wrong weight. Add `bloom_level` to
`activity_item` at that point.
