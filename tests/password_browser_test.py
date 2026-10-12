"""EduFlex — end to end check of the show/hide password control.

Drives the real control on both public forms and asserts the things that cannot
be seen in markup: that clicking it does not submit the form, that the field
really changes between hidden and readable, that the caret survives the swap,
and that the two controls on the registration page are independent of each
other.

The form-submit check is the reason this file exists. A <button> inside a <form>
submits it by default. Without type="button", revealing your password posts the
login form, which presents as a mysteriously broken login rather than as a
missing attribute, and you look for it in the wrong place entirely.

Needs NO database and writes nothing. It only reads two public pages, so it is
safe to run against a working install.

    1. Start Apache in XAMPP.
    2. python3 tests/password_browser_test.py

Set EDUFLEX_BASE if your URL differs. Set EDUFLEX_CHROMIUM to a Chrome binary if
playwright's own download was never completed; on this machine that is
C:/Program Files/Google/Chrome/Application/chrome.exe
"""

import os
import sys
from playwright.sync_api import sync_playwright

BASE = os.environ.get("EDUFLEX_BASE", "http://localhost/eduflex-ui")

failures = []
passed = 0

TOGGLE = ".ef-password-toggle"


def check(label, cond):
    global passed
    print(("  PASS  " if cond else "  FAIL  ") + label)
    if cond:
        passed += 1
    else:
        failures.append(label)


def section(name):
    print("\n" + name)


print("EduFlex password reveal suite\n=============================")

with sync_playwright() as p:
    browser = p.chromium.launch(
        executable_path=os.environ.get("EDUFLEX_CHROMIUM") or None)
    page = browser.new_page(viewport={"width": 1280, "height": 900})

    errors = []
    page.on("console", lambda m: errors.append(m.text) if m.type == "error" else None)
    page.on("pageerror", lambda e: errors.append(str(e)))

    section("Login, at rest")
    page.goto(BASE + "/login.php", wait_until="load")
    page.wait_for_timeout(500)
    check("exactly one control is present", page.locator(TOGGLE).count() == 1)
    check("the field starts hidden",
          page.eval_on_selector("#password", "e => e.type") == "password")
    check("the open eye is the one showing",
          page.eval_on_selector(TOGGLE + " .ef-eye-open",
                                "e => getComputedStyle(e).display") != "none"
          and page.eval_on_selector(TOGGLE + " .ef-eye-shut",
                                    "e => getComputedStyle(e).display") == "none")

    section("Revealing")
    page.fill("#password", "SuperSecret123")
    before = page.url
    page.click(TOGGLE)
    page.wait_for_timeout(400)
    # The one that matters most.
    check("clicking it did NOT submit the form", page.url == before)
    check("the password is now readable",
          page.eval_on_selector("#password", "e => e.type") == "text")
    check("the typed value survived the swap",
          page.eval_on_selector("#password", "e => e.value") == "SuperSecret123")
    check("aria-pressed became true",
          page.eval_on_selector(TOGGLE, "e => e.getAttribute('aria-pressed')") == "true")
    check("the accessible name became Hide password",
          page.eval_on_selector(TOGGLE, "e => e.getAttribute('aria-label')") == "Hide password")
    check("the slashed eye is the one showing",
          page.eval_on_selector(TOGGLE + " .ef-eye-shut",
                                "e => getComputedStyle(e).display") != "none"
          and page.eval_on_selector(TOGGLE + " .ef-eye-open",
                                    "e => getComputedStyle(e).display") == "none")

    section("Hiding again")
    page.click(TOGGLE)
    page.wait_for_timeout(400)
    check("the field is hidden again",
          page.eval_on_selector("#password", "e => e.type") == "password")
    check("aria-pressed went back to false",
          page.eval_on_selector(TOGGLE, "e => e.getAttribute('aria-pressed')") == "false")

    section("The caret")
    # Changing input.type sends the caret to the end in most engines, which is
    # maddening halfway through typing a long password.
    page.eval_on_selector("#password", "e => { e.focus(); e.setSelectionRange(5, 5); }")
    page.click(TOGGLE)
    page.wait_for_timeout(300)
    check("it stayed where the typist left it",
          page.eval_on_selector("#password", "e => e.selectionStart") == 5)

    section("Keyboard only")
    page.goto(BASE + "/login.php", wait_until="load")
    page.wait_for_timeout(400)
    page.focus("#password")
    page.keyboard.press("Tab")
    page.keyboard.press("Enter")
    page.wait_for_timeout(400)
    check("it is reachable by Tab and operable by Enter",
          page.eval_on_selector("#password", "e => e.type") == "text")

    section("Registration has two, and they are independent")
    page.goto(BASE + "/register.php", wait_until="load")
    page.wait_for_timeout(500)
    check("both controls are present", page.locator(TOGGLE).count() == 2)
    page.fill("#password", "aaaaaaaa")
    page.fill("#confirm_password", "bbbbbbbb")
    before = page.url
    page.locator(TOGGLE).first.click()
    page.wait_for_timeout(400)
    check("clicking it did NOT submit the form", page.url == before)
    check("only the field it belongs to was revealed",
          page.eval_on_selector("#password", "e => e.type") == "text"
          and page.eval_on_selector("#confirm_password", "e => e.type") == "password")
    page.locator(TOGGLE).nth(1).click()
    page.wait_for_timeout(400)
    check("the second one works on its own field",
          page.eval_on_selector("#confirm_password", "e => e.type") == "text")

    section("Layout")
    metrics = page.evaluate("""() => {
        const i = document.querySelector('#password');
        const b = document.querySelector('.ef-password-toggle');
        const ir = i.getBoundingClientRect(), br = b.getBoundingClientRect();
        return {
            insideRight: br.right <= ir.right + 1,
            insideLeft: br.left > ir.left,
            padRight: parseFloat(getComputedStyle(i).paddingRight),
            offCentre: Math.abs((br.top + br.height / 2) - (ir.top + ir.height / 2)),
            tall: br.height
        };
    }""")
    check("the button sits inside the field", metrics["insideRight"] and metrics["insideLeft"])
    check("the field is padded so text cannot run under it", metrics["padRight"] >= 40)
    check("it is vertically centred", metrics["offCentre"] < 1.5)
    # Smaller than this is awkward to hit on a phone.
    check("it is a comfortable tap target", metrics["tall"] >= 30)

    section("Narrow screen")
    phone = browser.new_page(viewport={"width": 390, "height": 844})
    phone.goto(BASE + "/register.php", wait_until="load")
    phone.wait_for_timeout(500)
    check("both controls stay inside the fields on a phone",
          phone.evaluate("""() => {
              return [...document.querySelectorAll('.ef-password-toggle')].every(b => {
                  const r = b.getBoundingClientRect();
                  return r.left >= 0 && r.right <= document.documentElement.clientWidth;
              });
          }"""))
    phone.close()

    section("Overall")
    check("no JavaScript errors at any point", not errors)
    if errors:
        print("        " + repr(errors[:4]))

    page.close()
    browser.close()

print("\n=============================")
print("Passed: %d   Failed: %d" % (passed, len(failures)))
if failures:
    print("\nFailed checks:")
    for f in failures:
        print("  - " + f)
sys.exit(1 if failures else 0)
