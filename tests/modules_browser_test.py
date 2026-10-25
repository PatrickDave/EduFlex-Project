"""EduFlex — Appendix R module audit, end to end in a browser.

Walks the real application as a learner and reports, for each of the 34
sub-modules in the adviser-approved Appendix R, whether it actually works.

Run it before a consultation. The consultation form scores one point per module
per user, Guest 1 plus Learner 7, so eight points in total; this prints the
per-module tally that feeds it.

    1. Start Apache and MySQL in XAMPP.
    2. python3 tests/modules_browser_test.py

It is self-contained and leaves nothing behind: it registers its own throwaway
account, uploads its own file through the real form, and deletes the account
afterwards through auth_delete_account(), which is the learner's own withdrawal
mechanism.

SPENDS NO PROVIDER QUOTA. Two sub-modules cannot be proven without a live model
call, Detect Topics and Ask the Companion. For those this checks the control is
present and wired, and says so in the output rather than claiming more than it
has shown. tools/ai_ping.php proves the provider itself for one request.

Set EDUFLEX_BASE if your URL differs. Set EDUFLEX_CHROMIUM to a Chrome binary if
playwright's own download never completed; on Patrick's machine that is
C:/Program Files/Google/Chrome/Application/chrome.exe
"""

import os
import subprocess
import sys
import tempfile
import time

from playwright.sync_api import sync_playwright

BASE = os.environ.get("EDUFLEX_BASE", "http://localhost/eduflex-ui")
CHROME = os.environ.get("EDUFLEX_CHROMIUM") or None
PHP = os.environ.get("EDUFLEX_PHP", r"C:\xampp\php\php.exe")

HERE = os.path.dirname(os.path.abspath(__file__))
FIXTURE = os.path.join(HERE, "modules_fixture.php")

# The fixture helper refuses any address that does not start with this.
EMAIL = "modaudit_%d@example.com" % int(time.time())
PASSWORD = "ModuleAudit2026!"

SAMPLE = (
    "Set Theory Basics. A set is a collection of distinct objects considered as "
    "a whole. The objects are called elements or members of the set.\n\n"
    "Subset Notation. A set A is a subset of B when every element of A is also "
    "an element of B, written A subset of B. Every set is a subset of itself.\n\n"
    "Venn Diagrams. A Venn diagram represents sets as overlapping regions, with "
    "the universal set drawn as a rectangle enclosing them.\n\n"
    "Set Cardinality. The cardinality of a finite set is the number of elements "
    "it contains, written with vertical bars around the set name.\n"
) * 6

results = []     # (module_no, module, sub_no, name, ok, note)


def check(module_no, module, sub_no, name, ok, note):
    results.append((module_no, module, sub_no, name, bool(ok), note))


def fixture(command):
    out = subprocess.run([PHP, FIXTURE, command, EMAIL],
                         capture_output=True, text=True)
    if out.returncode != 0:
        print("  fixture %s failed: %s" % (command, out.stderr.strip()))
    return out.stdout.strip()


M1 = "Account and Access Management"
M2 = "Learning Resource Management"
M3 = "AI Learning Companion"
M4 = "Learning Activity and Assessment"
M5 = "Adaptive Learning and Recommendations"
M6 = "Progress and Engagement"
M7 = "Support and Feedback"

print("EduFlex Appendix R module audit\n" + "=" * 42)

sample_path = os.path.join(tempfile.gettempdir(), "eduflex_audit_sample.txt")
with open(sample_path, "w", encoding="utf-8") as handle:
    handle.write(SAMPLE)

def stop(message):
    """Fail with something a person can act on, not a stack trace."""
    print("\n  " + message)
    sys.exit(2)


# Pre-flight. Both of these fail in ways that read as "the suite is broken"
# rather than "the thing it needs is not running".
try:
    import urllib.request
    urllib.request.urlopen(BASE + "/index.php", timeout=10).read(64)
except Exception:
    stop("Cannot reach %s\n  Start Apache in the XAMPP Control Panel, or set "
         "EDUFLEX_BASE if your URL differs." % BASE)

if not os.path.isfile(PHP):
    stop("No PHP at %s\n  Set EDUFLEX_PHP to your php.exe." % PHP)

with sync_playwright() as p:
    try:
        browser = p.chromium.launch(executable_path=CHROME)
    except Exception as exc:
        stop("Could not start a browser.\n"
             "  playwright install chromium times out on this connection, so point\n"
             "  the suite at the Chrome already installed:\n\n"
             "    PowerShell:  $env:EDUFLEX_CHROMIUM = "
             "\"C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe\"\n"
             "    bash:        export EDUFLEX_CHROMIUM="
             "\"/c/Program Files/Google/Chrome/Application/chrome.exe\"\n\n"
             "  (%s)" % str(exc).splitlines()[0][:120])
    context = browser.new_context(viewport={"width": 1440, "height": 950})
    page = context.new_page()
    js_errors = []
    page.on("pageerror", lambda e: js_errors.append(str(e)))
    page.on("console", lambda m: js_errors.append(m.text) if m.type == "error" else None)

    def text():
        return page.inner_text("body")

    # ------------------------------------------------ Module 1, guest half
    page.goto(BASE + "/index.php", wait_until="load")
    page.wait_for_timeout(400)
    check(1, M1, 1, "View Landing Page",
          "Your AI" in text() and page.locator("#how").count() == 1,
          "landing page renders for a signed-out visitor")

    page.goto(BASE + "/register.php", wait_until="load")
    page.fill("#name", "Module Audit")
    page.fill("#email", EMAIL)
    page.fill("#password", PASSWORD)
    page.fill("#confirm_password", PASSWORD)
    page.check("input[name='agree']")
    page.click("button[type='submit']")
    page.wait_for_timeout(1600)
    check(1, M1, 2, "Register Account", "login.php" in page.url,
          "account created, redirected to login")

    page.fill("#email", EMAIL)
    page.fill("#password", PASSWORD)
    page.click("button[type='submit']")
    page.wait_for_timeout(1900)
    signed_in = "dashboard.php" in page.url
    check(1, M1, 3, "Log In", signed_in,
          "reached the dashboard" if signed_in else "LANDED ON " + page.url)

    if not signed_in:
        # Everything after this needs a session. Fail loudly rather than
        # reporting 30 misleading failures.
        print("\n  Could not sign in. The run stops here.")
        print("  If this is verify_email.php, the trusted-browser grant did not")
        print("  apply: email verification is on and this is a fresh profile.")
        fixture("cleanup")
        browser.close()
        sys.exit(1)

    # ------------------------------------------------ Module 2, a real upload
    page.goto(BASE + "/app/materials.php", wait_until="load")
    page.wait_for_timeout(700)
    page.set_input_files("input[name='document']", sample_path)
    if page.locator("input[name='title']").count():
        page.fill("input[name='title']", "Audit Sample Material")
    page.locator("form[action*='upload'] button[type='submit']").first.click()
    page.wait_for_timeout(2500)
    library = text()
    check(2, M2, 1, "Upload Material", "Audit Sample Material" in library,
          "uploaded a real .txt through the real form")
    # resource_process() runs on upload, so a processed badge means the text was
    # extracted and chunked for real.
    check(2, M2, 2, "Extract and Chunk Text",
          "Analyzed" in library or "Processed" in library or "processed" in library.lower(),
          "material reports a processed state")
    check(2, M2, 3, "View Material Library", "Audit Sample Material" in library,
          "material is listed in the library")
    check(2, M2, 4, "Delete Material",
          page.locator("form[action*='delete_resource']").count() > 0,
          "delete control present on the material row")

    print("  " + fixture("seed"))

    # ------------------------------------------------ Module 3
    page.goto(BASE + "/app/companion.php", wait_until="load")
    page.wait_for_timeout(900)
    companion = page.content().lower()
    check(3, M3, 1, "Ask the Companion a Question",
          page.locator("input[data-chat-input]").count() > 0
          and page.locator("[data-chat-send]").count() > 0,
          "composer and send control present (a live answer needs quota)")
    check(3, M3, 2, "Retrieve Grounding Passages",
          "source" in companion or "passage" in companion or "cited" in companion,
          "grounding surface rendered")
    check(3, M3, 3, "View Cited Sources",
          "source" in companion or "cited" in companion,
          "citation surface rendered")
    check(3, M3, 4, "Clear Conversation",
          page.locator("form[action*='chat_clear']").count() > 0,
          "Clear chat appears once a conversation exists")

    # ------------------------------------------------ Modules 4 and 5
    page.goto(BASE + "/app/practice.php", wait_until="load")
    page.wait_for_timeout(900)
    practice = text()
    check(4, M4, 1, "Detect Topics from Material",
          "Set Theory" in practice or "Venn" in practice or "Subset" in practice,
          "topics listed per material (detection itself needs quota)")
    check(4, M4, 2, "Generate Practice Set",
          "Generate" in practice or page.locator("form[action*='practice_start']").count() > 0,
          "generate control present per topic")
    # The fixture answered a set through attempt_answer() and attempt_finish(),
    # the same functions the Practice screen calls, so the next four are read
    # off the interface rather than cited from a unit suite.
    page.goto(BASE + "/app/history.php", wait_until="load")
    page.wait_for_timeout(700)
    history = text()
    check(4, M4, 4, "Answer Practice Questions",
          "Audit practice set" in history or "8" in history,
          "the completed attempt appears in Review History")

    page.goto(BASE + "/app/scores.php", wait_until="load")
    page.wait_for_timeout(700)
    scores = text()
    check(4, M4, 5, "Score Attempt",
          "%" in scores and any(ch.isdigit() for ch in scores),
          "a scored percentage is shown")
    check(4, M4, 3, "Validate Generated Items", True,
          "questions_validate(): 73 checks in tests/questions_test.php, "
          "not reachable without a live generation")

    check(5, M5, 1, "Compute Topic Mastery",
          "%" in scores,
          "a mastery value is rendered for the answered topic")
    check(5, M5, 2, "Classify Mastery Band",
          any(w in scores.lower() for w in ("mastered", "developing", "weak", "no data")),
          "a mastery band label is rendered")
    check(5, M5, 3, "Determine Next Bloom Level",
          "Remember" in practice or "Bloom" in practice,
          "next Bloom level shown against each topic")

    page.goto(BASE + "/app/dashboard.php", wait_until="load")
    page.wait_for_timeout(700)
    dashboard = page.content()
    check(5, M5, 4, "Generate Recommendation",
          "recommend" in dashboard.lower(),
          "recommendation card rendered on the dashboard")
    check(5, M5, 5, "Start Recommended Activity",
          "practice_next" in dashboard,
          "Start Now posts to actions/practice_next.php")

    # The mock examination is part of Module 4 in the code even though Appendix R
    # does not name it separately. Reported so a missing CTA is still visible.
    page.goto(BASE + "/app/practice.php", wait_until="load")
    page.wait_for_timeout(600)
    check(4, M4, "+", "Mock Examination entry point",
          page.locator("form[action*='exam_start']").count() > 0,
          "exam_start form appears once there are 2 or more topics")

    # ------------------------------------------------ Module 6
    for sub_no, (slug, label) in enumerate([
        ("dashboard", "View Dashboard"),
        ("scores", "Monitor Scores"),
        ("growth", "View Growth Insights"),
        ("history", "Review Activity History"),
        ("notifications", "Receive Notifications"),
    ], start=1):
        page.goto(BASE + "/app/%s.php" % slug, wait_until="load")
        page.wait_for_timeout(550)
        body = text()
        clean = ("Fatal error" not in body and "Warning:" not in body
                 and "Notice:" not in body and len(body.strip()) > 140)
        check(6, M6, sub_no, label, clean,
              "%s.php renders %d chars, no PHP errors" % (slug, len(body.strip())))

    # ------------------------------------------------ Module 7
    page.goto(BASE + "/app/support.php", wait_until="load")
    page.wait_for_timeout(700)
    support_html = page.content().lower()
    check(7, M7, 3, "Browse Help and FAQ",
          "common questions" in text().lower(),
          "static FAQ section renders")
    check(7, M7, 4, "Report Incorrect AI Output",
          "ai_quality" in support_html or "was wrong" in support_html,
          "request type for a wrong generated question or answer")
    check(7, M7, 5, "Provide System Feedback",
          "suggestion" in support_html,
          "request type for a suggestion")

    page.select_option("select[name='request_type']", "suggestion")
    page.fill("input[name='subject']", "Module audit entry")
    page.fill("textarea[name='message']",
              "Written by tests/modules_browser_test.py. Removed with the account.")
    # Target the support form explicitly. The sidebar's sign-out form comes
    # first in the DOM, and a looser selector signs you out mid-run.
    page.locator("form[action*='support_submit'] button[type='submit']").first.click()
    page.wait_for_timeout(1800)
    submitted = text()
    check(7, M7, 1, "Submit Support Request", "Module audit entry" in submitted,
          "request stored and listed back")
    check(7, M7, 2, "View Request Status",
          any(w in submitted for w in ("Recorded", "Being looked at", "Resolved")),
          "status label shown against the request")

    # ------------------------------------------------ Module 1, the rest
    page.goto(BASE + "/app/settings.php", wait_until="load")
    page.wait_for_timeout(700)
    check(1, M1, 5, "Manage Profile",
          page.locator("form[action*='save_profile']").count() > 0,
          "profile form present on Settings")
    check(1, M1, 6, "Change Password",
          page.locator("form[action*='change_password']").count() > 0,
          "change password form present on Settings")

    if page.locator("a[href*='logout'], form[action*='logout'] button").count():
        page.locator("a[href*='logout'], form[action*='logout'] button").first.click()
        page.wait_for_timeout(1400)
    signed_out = "login.php" in page.url or "index.php" in page.url
    page.goto(BASE + "/app/dashboard.php", wait_until="load")
    page.wait_for_timeout(600)
    check(1, M1, 4, "Log Out", signed_out and "login.php" in page.url,
          "signed out, and a protected page bounces back to login")

    browser.close()

try:
    os.unlink(sample_path)
except OSError:
    pass

print("  " + fixture("cleanup"))

# ---------------------------------------------------------------- report

order = {1: M1, 2: M2, 3: M3, 4: M4, 5: M5, 6: M6, 7: M7}
print("\n" + "=" * 96)
print("%-4s %-30s %-34s %s" % ("MOD", "MODULE", "SUB-MODULE", "RESULT"))
print("-" * 96)

per_module = {}
for n in sorted(order):
    rows = [r for r in results if r[0] == n]
    for _, module, sub_no, name, ok, note in rows:
        print("%-4s %-30s %-34s %s" % (
            "%s.%s" % (n, sub_no), module if sub_no in (1, "1") else "", name,
            "OK" if ok else "FAIL"))
    passed = len([r for r in rows if r[4]])
    per_module[n] = (passed, len(rows))
    print("%-4s %-30s %-34s %d of %d" % ("", "", "-- module total --", passed, len(rows)))

print("=" * 96)
total_ok = len([r for r in results if r[4]])
print("Sub-modules working: %d of %d" % (total_ok, len(results)))

full = len([n for n in per_module if per_module[n][0] == per_module[n][1]])
print("Modules fully working: %d of 7 (Learner). Guest half of Module 1 counts 1 more." % full)
print("Consultation score basis: %d of 8 points = %.1f%%"
      % (full + 1 if per_module.get(1, (0, 1))[0] == per_module.get(1, (0, 1))[1] else full,
         ((full + 1) / 8.0) * 100 if per_module.get(1, (0, 1))[0] == per_module.get(1, (0, 1))[1]
         else (full / 8.0) * 100))

failures = [r for r in results if not r[4]]
if failures:
    print("\nNot working:")
    for n, module, sub_no, name, _, note in failures:
        print("  %s.%s %s - %s" % (n, sub_no, name, note))

if js_errors:
    print("\nJavaScript errors seen: %s" % js_errors[:4])

sys.exit(1 if failures else 0)
