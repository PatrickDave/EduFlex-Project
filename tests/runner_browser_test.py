"""EduFlex — end to end check of the question runner.

Registers an account, seeds one processed document, generates a set through the
Practice screen, answers every question, finishes, and verifies that the score
and the topic's mastery were actually written to the database.

Unlike the PHP suites this one needs a running system:

    1. Start Apache and MySQL in XAMPP.
    2. Import database/01_schema.sql.
    3. Set BASE below to your URL, for example http://localhost/eduflex-ui.
    4. pip install playwright && playwright install chromium
    5. python3 tests/runner_browser_test.py

WARNING: reset() drops and recreates the eduflex database on every run. Never
point this at a database whose contents you want to keep."""

import os
import re
import subprocess
import tempfile
import sys
from playwright.sync_api import sync_playwright

BASE = os.environ.get("EDUFLEX_BASE", "http://127.0.0.1:8080")
SCHEMA = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                      "..", "database", "01_schema.sql")
SHOTS = os.environ.get("EDUFLEX_SHOTS", tempfile.mkdtemp(prefix="eduflex-shots-"))

failures = []


def check(label, cond):
    print(("  PASS  " if cond else "  FAIL  ") + label)
    if not cond:
        failures.append(label)


def sql(query):
    return subprocess.run(
        ["mariadb", "-uroot", "-N", "-B", "eduflex", "-e", query],
        capture_output=True, text=True,
    ).stdout.strip()


def reset():
    """Each run starts from an empty database, so the test proves the flow
    rather than inheriting state from the last attempt."""
    subprocess.run(["bash", "-c",
                    "mariadb -uroot < %s" % SCHEMA],
                   check=True)


reset()

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=os.environ.get("EDUFLEX_CHROMIUM") or None)
    page = browser.new_page(viewport={"width": 1440, "height": 950})

    print("\nRegistration and sign in")
    page.goto(BASE + "/register.php")
    page.fill('input[name="full_name"]', "Patrick Cagas")
    page.fill('input[name="email"]', "runner@example.com")
    page.fill('input[name="password"]', "Practice-2026")
    page.check('input[name="agree"]')
    page.click('button[type="submit"]')
    page.wait_for_load_state("networkidle")
    check("registration hands off to the login screen", "login.php" in page.url)
    check("the handoff says so", "Account created" in page.inner_text("body"))

    page.fill('input[name="email"]', "runner@example.com")
    page.fill('input[name="password"]', "Practice-2026")
    page.click('button[type="submit"]')
    page.wait_for_load_state("networkidle")
    check("signing in lands inside the app", "/app/" in page.url)

    uid = sql("SELECT user_id FROM `user` WHERE email='runner@example.com'")
    check("the account exists", uid.isdigit())

    # Seed a processed resource with real chunk text. The upload path is
    # already covered by its own suite; this test is about the runner.
    passage = (
        "The Nyquist sampling theorem states that a band limited signal can be "
        "reconstructed exactly when the sampling rate is at least twice the highest "
        "frequency present in the signal. Sampling below that rate causes aliasing, "
        "where high frequency components are indistinguishable from lower ones after "
        "reconstruction. Engineers therefore place an anti aliasing low pass filter "
        "before the sampler so the band limit is guaranteed rather than assumed."
    )
    sql(
        "INSERT INTO learning_resource (user_id, title, file_type, storage_path, "
        "processing_status, original_name, char_count, chunk_count, processed_at) "
        "VALUES (%s, 'Signals Reviewer', 'txt', 'storage/seed.txt', 'processed', "
        "'signals.txt', %d, 1, NOW())" % (uid, len(passage))
    )
    rid = sql("SELECT resource_id FROM learning_resource WHERE user_id=%s" % uid)
    sql(
        "INSERT INTO resource_chunk (resource_id, chunk_index, content, word_count) "
        "VALUES (%s, 0, '%s', %d)" % (rid, passage.replace("'", "''"), len(passage.split()))
    )
    sql(
        "INSERT INTO topic_progress (user_id, resource_id, topic_name) "
        "VALUES (%s, %s, 'Nyquist Sampling')" % (uid, rid)
    )
    tpid = sql("SELECT topic_progress_id FROM topic_progress WHERE user_id=%s" % uid)
    check("a topic is seeded", tpid.isdigit())

    print("\nGenerating a set from the Practice screen")
    page.goto(BASE + "/app/practice.php")
    page.wait_for_load_state("networkidle")
    check("the topic is offered for generation",
          page.query_selector('[data-generate]') is not None)
    page.screenshot(path=SHOTS + "/run-1-practice.png", full_page=True)

    page.click('[data-generate]')
    page.wait_for_timeout(3500)
    page.wait_for_load_state("networkidle")

    items = sql("SELECT COUNT(*) FROM activity_item ai JOIN learning_activity la "
                "ON la.activity_id=ai.activity_id WHERE la.topic_progress_id=%s" % tpid)
    check("questions were stored", items.isdigit() and int(items) > 0)
    print("        stored items: %s" % items)

    print("\nStarting the attempt")
    start = page.query_selector('form[action="actions/attempt_start.php"] button')
    check("the Start button is live", start is not None and not start.is_disabled())
    start.click()
    page.wait_for_load_state("networkidle")
    check("the runner opens", "practice_run.php?attempt=" in page.url)

    attempt_id = re.search(r"attempt=(\d+)", page.url).group(1)
    total = int(page.get_attribute(".ef-runner", "data-total"))
    check("the runner knows how many questions there are", total > 0)
    print("        attempt %s, %d questions" % (attempt_id, total))

    visible = page.query_selector_all('.ef-q:not([hidden])')
    check("exactly one question is shown", len(visible) == 1)
    page.screenshot(path=SHOTS + "/run-2-question.png", full_page=True)

    print("\nThe correct answer is not in the page")
    html = page.content()
    answers = sql("SELECT ai.correct_answer FROM activity_item ai "
                  "JOIN activity_attempt aa ON aa.activity_id = ai.activity_id "
                  "WHERE aa.attempt_id=%s" % attempt_id).splitlines()
    # Every option text is in the page, so the test is that the page cannot say
    # WHICH one is right: no attribute or marker names the stored answer.
    leaked = re.findall(r'(?:correct|answer)["\']?\s*[:=]\s*["\'][^"\']+', html, re.I)
    check("no correct-answer marker is rendered", leaked == [])
    check("there are answers to leak", len(answers) > 0)

    print("\nAnswering every question")
    right = 0
    for i in range(total):
        q = page.query_selector('.ef-q:not([hidden])')
        check("question %d is shown" % (i + 1), q is not None)
        if q is None:
            break
        if i == total - 1:
            # Leave the last one skipped, to prove a skip is recorded and
            # counts against the score.
            q.query_selector('[data-skip]').click()
        else:
            q.query_selector_all('.ef-option')[0].click()
        page.wait_for_timeout(700)

        fb = q.query_selector('.ef-feedback')
        check("feedback appears for question %d" % (i + 1),
              fb is not None and fb.is_visible())
        if fb and "ef-feedback-right" in (fb.get_attribute("class") or ""):
            right += 1

        if i == 0:
            page.screenshot(path=SHOTS + "/run-3-feedback.png", full_page=True)

        nxt = q.query_selector('[data-next]')
        check("the next control appears for question %d" % (i + 1),
              nxt is not None and nxt.is_visible())
        nxt.click()
        page.wait_for_timeout(600)

    print("\nScoring")
    page.wait_for_url(re.compile(r"practice_run\.php\?attempt=" + attempt_id), timeout=15000)
    page.wait_for_load_state("networkidle")
    body = page.inner_text("body")
    check("the result screen is shown", "Result" in body and "Score" in body)

    stored = sql("SELECT score, total_items, completed_at FROM activity_attempt "
                 "WHERE attempt_id=%s" % attempt_id).split("\t")
    check("a score was written", stored[0] not in ("", "NULL"))
    check("the attempt is closed", stored[2] not in ("", "NULL"))
    print("        stored score: %s over %s items" % (stored[0], stored[1]))

    expected = round(right / total * 100, 2)
    check("the stored score matches the answers given (%.2f)" % expected,
          abs(float(stored[0]) - expected) < 0.01)
    check("the skipped question counted against the score",
          float(stored[0]) < 100.0)

    responses = sql("SELECT COUNT(*) FROM attempt_response WHERE attempt_id=%s" % attempt_id)
    check("every question was recorded, skip included", int(responses) == total)

    print("\nMastery")
    row = sql("SELECT mastery_score, scored_items, weakness_priority "
              "FROM topic_progress WHERE topic_progress_id=%s" % tpid).split("\t")
    check("scored_items was written", int(row[1]) == total)
    check("a mastery value was written", float(row[0]) >= 0)
    print("        mastery %s over %s items, band %s" % (row[0], row[1], row[2]))
    page.screenshot(path=SHOTS + "/run-4-result.png", full_page=True)

    reviewed = page.inner_text(".ef-review-item") if page.query_selector(".ef-review-item") else ""
    check("the review lists the questions",
          len(page.query_selector_all(".ef-review-item")) == total)
    check("the review names the correct answer", "The answer is" in body)
    check("the skipped question is reported", "Skipped" in body)

    print("\nThe dashboard shows the result")
    page.goto(BASE + "/app/dashboard.php")
    page.wait_for_load_state("networkidle")
    dash = page.inner_text("body")
    check("the topic appears on the dashboard", "Nyquist Sampling" in dash)
    page.screenshot(path=SHOTS + "/run-5-dashboard.png", full_page=True)

    print("\nGuards")
    page.goto(BASE + "/app/practice_run.php?attempt=999999")
    page.wait_for_load_state("networkidle")
    check("an unknown attempt redirects to Practice", "practice.php" in page.url)
    check("the reason is shown", "not found" in page.inner_text("body").lower())

    page.goto(BASE + "/app/practice_run.php?attempt=" + attempt_id)
    page.wait_for_load_state("networkidle")
    check("a finished attempt reopens as its result",
          "Result" in page.inner_text("body"))

    print("\nMobile")
    page.set_viewport_size({"width": 390, "height": 844})
    page.reload()
    page.wait_for_load_state("networkidle")
    ring = page.query_selector(".ef-score-ring")
    check("the result screen fits a phone",
          ring is not None and ring.bounding_box()["x"] >= 0)
    check("nothing scrolls sideways",
          page.evaluate("document.documentElement.scrollWidth <= window.innerWidth + 1"))
    page.screenshot(path=SHOTS + "/run-6-mobile.png", full_page=True)


    print("\nPractising what EduFlex recommends")
    page.set_viewport_size({"width": 1440, "height": 950})
    page.goto(BASE + "/app/practice.php")
    page.wait_for_load_state("networkidle")

    reco = sql("SELECT COUNT(*) FROM recommendation WHERE status IN ('new','viewed')")
    check("a recommendation exists after a scored attempt", int(reco) >= 1)

    btn = page.query_selector('form[action="actions/practice_next.php"] button')
    check("the recommendation card offers a button", btn is not None)
    page.screenshot(path=SHOTS + "/run-7-recommendation.png", full_page=True)

    before = int(sql("SELECT COUNT(*) FROM learning_activity"))
    btn.click()
    page.wait_for_load_state("networkidle")
    check("it opens the runner directly", "practice_run.php?attempt=" in page.url)
    check("on a real question",
          page.query_selector('.ef-q:not([hidden]) .ef-option') is not None)

    after = int(sql("SELECT COUNT(*) FROM learning_activity"))
    # The only stored set was completed earlier in this test, so there was
    # nothing free to reuse and a new one is the correct outcome here.
    check("a set was written because none was free", after == before + 1)
    check("and the recommendation is marked as acted on",
          int(sql("SELECT COUNT(*) FROM recommendation WHERE status='accepted'")) >= 1)

    # Pressing it again must cost nothing: the attempt just opened is still
    # unfinished, so it is resumed rather than replaced.
    page.goto(BASE + "/app/practice.php")
    page.wait_for_load_state("networkidle")
    repeat = page.query_selector('form[action="actions/practice_next.php"] button')
    if repeat:
        repeat.click()
        page.wait_for_load_state("networkidle")
    check("pressing it again writes no second set",
          int(sql("SELECT COUNT(*) FROM learning_activity")) == after)
    check("and does not stack a second open attempt",
          int(sql("SELECT COUNT(*) FROM activity_attempt WHERE completed_at IS NULL")) == 1)

    print("\nThe sidebar button does the same")
    page.goto(BASE + "/app/dashboard.php")
    page.wait_for_load_state("networkidle")
    page.click('.ef-sidebar-foot form button')
    page.wait_for_load_state("networkidle")
    check("the sidebar starts a session too", "practice_run.php?attempt=" in page.url)
    check("and resumes rather than stacking attempts",
          int(sql("SELECT COUNT(*) FROM activity_attempt WHERE completed_at IS NULL")) == 1)

    browser.close()

print("\n==================================")
print("Failed: %d" % len(failures))
for f in failures:
    print("  - " + f)
sys.exit(1 if failures else 0)
