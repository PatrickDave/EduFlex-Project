# Next layer: notifications, support, settings

**Status: built, 14 September 2026.** Everything below is implemented. Keep this
file as the record of why each decision was made; do not build it again. What
the finished layer does is documented in `README.md` sections 6d and 6e, and the
rules it added are rules 8 and 9 in `CLAUDE.md`. Where a judgment call went
beyond the brief it is noted inline below, marked BUILT.

Written 14 September 2026, at the point the build moved into VS Code. This is
the last layer before hardening. Read `CLAUDE.md` first.

Everything below is already in the schema. No migration is needed.

## Why this layer matters

Two links in the shell are dead right now. `partials/topbar.php` points at
`notifications.php` and `partials/sidebar.php` points at `support.php`, and
neither page exists. Every screen carries those links, so a panel member
clicking the bell during the defense gets a 404 on any page they are on.

That is the most visible defect left in the system. Fix it first.

## 1. Notifications

Page: `app/notifications.php`, generated from `build_pages.py` like every other
app page. Never hand-edit `app/*.php`.

Table `notification`: `notification_id`, `user_id`, `notification_type`,
`title`, `message`, `is_read`, `created_at`.

Nothing writes to this table yet. That is the real work: notifications must be
produced by events that already happen, not invented.

Write one on each of these, in the function that already performs the event:

| Event | Where | type |
|---|---|---|
| A document finished processing | `resource_process()` in `includes/resources.php` | `material_ready` |
| Topic detection found topics | `topics_detect()` in `includes/topics.php` | `topics_found` |
| A topic reached the mastered band | `mastery_recalculate()` in `includes/attempts.php`, only on the transition into `mastered` | `topic_mastered` |
| A new recommendation was created | `recommendation_refresh()` in `includes/attempts.php` | `recommendation` |

Rules:

- Writing a notification must never break the event that caused it. Wrap each
  in try/catch and log, the way `resource_mark()` already does.
- `topic_mastered` fires on the transition only. `mastery_recalculate()` already
  returns `previous` and `band`, so compare them. Firing on every recalculation
  would bury the learner.
- Never write a notification from a page render. Only from the action that
  caused it, or the count grows every time someone refreshes.

BUILT, and three departures from the table above, all following the same
"do not bury the learner" reasoning:

1. `topic_mastered` compares bands, not scores. `previous` is the previous
   score, which is not enough to tell a transition from a rise inside the band,
   so `mastery_recalculate()` now also reads `topic_progress.weakness_priority`,
   which holds the band it wrote last time, and returns it as `previous_band`.
2. `recommendation` fires only when the recommended TOPIC changes, not on every
   insert. `recommendation_refresh()` runs after every finished attempt and
   writes a fresh row each time, so notifying per insert would produce one
   "practise X next" per attempt for the same X. The row is still written every
   time; only the notification is conditional. If the panel wants the literal
   per-insert behaviour, delete the `$previousTopicId !== $topicId` guard.
3. `topics_found` fires only when detection actually inserted a topic, so
   re-running detection on an already-detected document is silent.

Also changed while here: the welcome notification in `auth_register()` was an
INSERT inside the registration transaction, which meant a failed notification
rolled back a valid registration. It now goes through `notify()` after the
commit, which is the rule above applied to the one place that already broke it.

Build `includes/notifications.php` with `notify()`, `notifications_list()`,
`notifications_unread_count()`, `notifications_mark_read()`,
`notifications_mark_all_read()`. Every query filters by `user_id`.

The topbar bell shows the unread count. That means `notifications_unread_count()`
runs on every app page, so keep it to one indexed `COUNT(*)`; the index
`idx_notification_user_read` already exists for it.

Empty state: a learner who has done nothing has no notifications. Say that
plainly, in the same three-stage style the other screens use. Do not seed
examples.

BUILT with two empty states, because there are two different nothings: no
notifications at all, and none unread. The first names the four events so the
learner knows what will appear. Nothing is seeded.

## 2. Support

Page: `app/support.php`. Table `support_request`: `request_id`, `user_id`,
`request_type`, `subject`, `message`, `status`, `created_at`.

- A form that writes one row, plus the learner's own previous requests with
  their status.
- `request_type` is a fixed list. Decide it in PHP, not from the POST body.
- CSRF on the form, `e()` on every rendered field, ownership by `user_id`.
- Be honest in the copy about what happens next. This is a capstone prototype
  with no staffed helpdesk, so the page should say the request is recorded for
  the project team rather than implying a support desk will reply.
- An FAQ section is worth having because the panel will ask about support, but
  write it as static content, not as fake tickets.

## 3. Settings

`app/settings.php` exists. Profile save works through
`app/actions/save_profile.php`. Three things are missing:

1. **Change password.** Current password, new password, confirm. Verify the
   current password with `password_verify()` before changing anything. Reuse the
   strength rule already in `includes/auth.php` rather than writing a second
   one. Call `session_regenerate_id(true)` after a successful change.
2. **Delete my account.** The foreign keys already cascade, so one delete
   removes everything. Require the learner to type their email to confirm.
   Nothing in the interface currently lets a participant withdraw their data,
   and Chapter III promises they can. This is an ethics commitment, not a
   feature.
3. **Export my data.** A JSON download of their materials, topics, attempts and
   mastery. Same reason as above, and it is a five-line query set.

There is no notification-preferences table. Do not add one before the freeze.
If the storyboard shows toggles, either cut them or note them as future work in
Chapter V. Do not ship a toggle that changes nothing.

## Definition of done

- `php -l` clean, `python3 build_pages.py` rerun, `./run_tests.sh` green.
- A new `tests/notifications_test.php` covering: a notification is written on
  each of the four events; `topic_mastered` fires on the transition only and not
  on every recalculation; a failed write does not break the event; unread count
  is per learner; one learner cannot read or mark another learner's rows.
- Settings tests: a wrong current password does not change anything; a
  successful change invalidates the old password; delete removes every row for
  that learner and nothing belonging to anyone else.
- No dead links left anywhere in `partials/`.
- `README.md` section 1 file list and the test counts updated.
- `CLAUDE.md` "Still to do" updated.

BUILT. Three notes on the definition of done:

- `partials/topbar.php` had a THIRD dead link nobody had listed: the search box
  posted to `search.php`, which does not exist either. It now posts to
  `materials.php`, which filters its list by `?q=` on title and file name. That
  is a real filter, not a stub.
- `./run_tests.sh` is green, but `tests/extract_test.php` used to crash rather
  than run on this machine: building a .docx fixture needs `ZipArchive`, and
  `extension=zip` is commented out in Patrick's php.ini. The suite now reports
  SKIP for that block and carries on. Enable `extension=zip`; DOCX uploads fail
  at runtime without it too, which has nothing to do with this layer.
- Two extra suites beyond the brief: `tests/support_test.php` (39 checks, mostly
  that `request_type` cannot come from the POST body) and the settings tests as
  their own `tests/settings_test.php` rather than bolted onto the auth suite,
  because proving the delete cascade needs a schema with real foreign keys and
  `PRAGMA foreign_keys = ON`.

## After this

Week 7 hardening, then the AI-output validation rubric run. That one needs a
real API key: set `AI_DRIVER` and `AI_API_KEY` in `config/ai.php`, generate
several sets from real material, and record how many questions the validator
discarded and why. Those counts are Chapter IV data and cannot be produced
against the mock provider.
