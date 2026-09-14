# Schema notes

`01_schema.sql` follows the Chapter III data dictionary (Tables 4 to 15). Five
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
