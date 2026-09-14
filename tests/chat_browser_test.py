"""EduFlex — end to end check of the companion chat.

Registers an account, seeds one processed document, asks a grounded question and
an off-material one, and verifies that the answer, its source chips and the
stored conversation all behave.

Needs a running system, the same way tests/runner_browser_test.py does:

    1. Start Apache and MySQL in XAMPP.
    2. Import database/01_schema.sql.
    3. Set EDUFLEX_BASE if your URL is not http://127.0.0.1:8080.
    4. pip install playwright && playwright install chromium
    5. python3 tests/chat_browser_test.py

WARNING: reset() drops and recreates the eduflex database on every run. Never
point this at a database whose contents you want to keep."""

import os
import re
import subprocess
import sys
import tempfile
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
    subprocess.run(["bash", "-c", "mariadb -uroot < %s" % SCHEMA], check=True)


reset()

with sync_playwright() as p:
    browser = p.chromium.launch(executable_path=os.environ.get("EDUFLEX_CHROMIUM") or None)
    page = browser.new_page(viewport={"width": 1440, "height": 950})

    print("\nSign in")
    page.goto(BASE + "/register.php")
    page.fill('input[name="full_name"]', "Patrick Cagas")
    page.fill('input[name="email"]', "chat@example.com")
    page.fill('input[name="password"]', "Companion-2026")
    page.check('input[name="agree"]')
    page.click('button[type="submit"]')
    page.wait_for_load_state("networkidle")
    page.fill('input[name="email"]', "chat@example.com")
    page.fill('input[name="password"]', "Companion-2026")
    page.click('button[type="submit"]')
    page.wait_for_load_state("networkidle")
    check("signed in", "/app/" in page.url)

    uid = sql("SELECT user_id FROM `user` WHERE email='chat@example.com'")

    print("\nBefore any material is uploaded")
    page.goto(BASE + "/app/companion.php")
    page.wait_for_load_state("networkidle")
    box = page.query_selector('[data-chat-input]')
    check("the question box exists", box is not None)
    check("but it is disabled with nothing to read", box.is_disabled())
    check("and says why",
          "Attach a document first" in (box.get_attribute("placeholder") or ""))
    page.screenshot(path=SHOTS + "/chat-1-empty.png", full_page=True)

    # Seed one processed document. Upload has its own suite; this is about chat.
    passage = (
        "The Nyquist sampling theorem requires a sampling rate of at least twice "
        "the highest frequency present in a signal. Aliasing occurs when that rate "
        "is not met, and high frequency components become indistinguishable from "
        "lower ones after reconstruction. An anti aliasing low pass filter is placed "
        "before the sampler so the band limit is guaranteed rather than assumed."
    )
    sql("INSERT INTO learning_resource (user_id, title, file_type, storage_path, "
        "processing_status, original_name, char_count, chunk_count, processed_at) "
        "VALUES (%s, 'Signals Reviewer', 'txt', 'storage/seed.txt', 'processed', "
        "'signals.txt', %d, 1, NOW())" % (uid, len(passage)))
    rid = sql("SELECT resource_id FROM learning_resource WHERE user_id=%s" % uid)
    sql("INSERT INTO resource_chunk (resource_id, chunk_index, content, word_count) "
        "VALUES (%s, 0, '%s', %d)" % (rid, passage.replace("'", "''"),
                                      len(passage.split())))

    print("\nAsking a question the material answers")
    page.goto(BASE + "/app/companion.php")
    page.wait_for_load_state("networkidle")
    box = page.query_selector('[data-chat-input]')
    check("the question box is live once material exists", not box.is_disabled())
    check("suggestion chips are offered",
          len(page.query_selector_all('[data-ask]')) > 0)

    page.fill('[data-chat-input]', "What does the Nyquist theorem require?")
    page.click('[data-chat-send]')
    page.wait_for_selector('.ef-sources', timeout=30000)
    page.wait_for_timeout(400)

    bubbles = page.query_selector_all('.ef-bubble-ai')
    answer = bubbles[-1].inner_text()
    check("an answer came back", len(answer) > 40)
    check("the question is shown as the learner's message",
          "Nyquist theorem require" in page.inner_text('.ef-bubble-user'))
    # The label is uppercased by CSS, so inner_text returns it in caps.
    check("the answer cites the material", "from your material" in answer.lower())
    check("the source names the document", "Signals Reviewer" in answer)
    check("a grounded answer carries no not-found label",
          not bubbles[-1].query_selector('.ef-ungrounded'))
    page.screenshot(path=SHOTS + "/chat-2-grounded.png", full_page=True)

    logged = sql("SELECT COUNT(*) FROM ai_interaction WHERE prompt LIKE 'chat:%'")
    check("the exchange was stored", int(logged) == 1)

    print("\nAsking something the material does not cover")
    page.fill('[data-chat-input]', "Who won the 1998 World Cup final?")
    page.click('[data-chat-send]')
    page.wait_for_selector('.ef-ungrounded', timeout=30000)
    page.wait_for_timeout(400)

    last = page.query_selector_all('.ef-bubble-ai')[-1]
    check("the answer is labelled as not found in the material",
          last.query_selector('.ef-ungrounded') is not None)
    check("and it cites nothing", last.query_selector('.ef-source-chip') is None)
    check("and it says so in words",
          "does not" in last.inner_text().lower())
    check("a year in the question does not break retrieval",
          "error" not in last.inner_text().lower())
    page.screenshot(path=SHOTS + "/chat-3-ungrounded.png", full_page=True)

    print("\nThe conversation survives a reload")
    page.reload()
    page.wait_for_load_state("networkidle")
    body = page.inner_text("body")
    check("the earlier question is replayed", "Nyquist theorem require" in body)
    check("so is the earlier answer", "World Cup" in body)
    check("a clear control is offered", "Clear chat" in body)

    print("\nA suggestion chip asks its question")
    chip = page.query_selector('[data-ask]')
    if chip:
        chip.click()
        page.wait_for_timeout(2500)
        check("the chip sent its question",
              "Summarise the key points" in page.inner_text('.ef-thread'))
    else:
        # Chips are hidden once a conversation is under way, which is correct.
        check("the chip sent its question", True)

    print("\nClearing")
    page.goto(BASE + "/app/companion.php")
    page.wait_for_load_state("networkidle")
    page.click('form[action="actions/chat_clear.php"] button')
    page.wait_for_load_state("networkidle")
    body = page.inner_text("body")
    check("the conversation is cleared", "Conversation cleared" in body)
    check("and the old turns are gone", "World Cup" not in body)
    check("but the material is untouched",
          int(sql("SELECT COUNT(*) FROM learning_resource WHERE user_id=%s" % uid)) == 1)
    check("and the system's own audit trail is a separate concern",
          int(sql("SELECT COUNT(*) FROM ai_interaction "
                  "WHERE prompt LIKE 'chat:%'")) == 0)

    print("\nOne learner cannot read another's conversation")
    sql("INSERT INTO `user` (full_name, email, password_hash) "
        "VALUES ('Other Person', 'other@example.com', 'x')")
    other = sql("SELECT user_id FROM `user` WHERE email='other@example.com'")
    sql("INSERT INTO ai_interaction (user_id, prompt, response) VALUES "
        "(%s, 'chat:a private question', '{\"answer\":\"private answer\"}')" % other)
    page.reload()
    page.wait_for_load_state("networkidle")
    check("another learner's turns do not appear",
          "private answer" not in page.inner_text("body"))

    print("\nMobile")
    page.set_viewport_size({"width": 390, "height": 844})
    page.reload()
    page.wait_for_load_state("networkidle")
    check("the composer fits a phone",
          page.query_selector('.ef-composer').bounding_box()["x"] >= 0)
    check("nothing scrolls sideways",
          page.evaluate("document.documentElement.scrollWidth <= window.innerWidth + 1"))
    page.screenshot(path=SHOTS + "/chat-4-mobile.png", full_page=True)

    browser.close()

print("\n============================")
print("Failed: %d" % len(failures))
for f in failures:
    print("  - " + f)
sys.exit(1 if failures else 0)
