"""EduFlex — end to end check of the explainer video dialog.

Opens the landing page, clicks both things that should open the explainer, and
verifies what static checks cannot see: that the dialog actually opens, that the
16 MB file is not fetched until somebody asks for it, and that closing the
dialog stops the audio.

That last one is the reason this file exists. A <dialog> that is closed is only
hidden, so a lightbox that forgets to pause leaves the narration playing from an
element nobody can see or find. It passes every markup assertion you can write.

Unlike the other two browser suites this one needs NO database and writes
nothing. It only reads a public page, so it is safe to run against Patrick's
working install.

    1. Start Apache in XAMPP.
    2. python3 tests/video_browser_test.py

Set EDUFLEX_BASE if your URL differs from the default. Set EDUFLEX_CHROMIUM to a
Chrome or Chromium binary if playwright's own download was never completed; on
this machine that is
C:/Program Files/Google/Chrome/Application/chrome.exe
"""

import os
import sys
from playwright.sync_api import sync_playwright

BASE = os.environ.get("EDUFLEX_BASE", "http://localhost/eduflex-ui")

failures = []
passed = 0


def check(label, cond):
    global passed
    print(("  PASS  " if cond else "  FAIL  ") + label)
    if cond:
        passed += 1
    else:
        failures.append(label)


def section(name):
    print("\n" + name)


DIALOG = "[data-video-dialog]"
VIDEO = "[data-video]"

print("EduFlex explainer video suite\n=============================")

with sync_playwright() as p:
    browser = p.chromium.launch(
        executable_path=os.environ.get("EDUFLEX_CHROMIUM") or None)
    page = browser.new_page(viewport={"width": 1280, "height": 900})

    errors = []
    page.on("console", lambda m: errors.append(m.text) if m.type == "error" else None)
    page.on("pageerror", lambda e: errors.append(str(e)))
    requests = []
    page.on("request", lambda r: requests.append(r.url))

    page.goto(BASE + "/index.php", wait_until="load")
    page.wait_for_timeout(600)

    section("Before anything is clicked")
    check("the page loads with no JavaScript errors", not errors)
    check("the dialog starts closed",
          page.evaluate("() => !document.querySelector('%s').open" % DIALOG))
    check("and is not laid out while closed",
          page.evaluate("() => getComputedStyle(document.querySelector('%s'))"
                        ".display === 'none'" % DIALOG))
    # preload="none" is what keeps this true. Most visitors never press play,
    # and 16 MB on every landing page view would be indefensible.
    check("the 16 MB video has not been requested",
          not any("eduflex-explainer.mp4" in u for u in requests))

    section("The hero button opens it")
    page.click("a.ef-btn-ghost[data-video-open]")
    page.wait_for_timeout(1200)
    check("the dialog opened",
          page.evaluate("() => document.querySelector('%s').open" % DIALOG))
    # The trigger is an <a href="#how">, so the handler has to prevent the jump.
    check("the page did not scroll to #how instead", "#how" not in page.url)
    check("the video began playing",
          page.evaluate("() => { const v = document.querySelector('%s');"
                        " return !v.paused && v.currentTime > 0; }" % VIDEO))
    check("the file was fetched only once it was needed",
          any("eduflex-explainer.mp4" in u for u in requests))
    check("the page behind it cannot scroll",
          page.evaluate("() => document.documentElement"
                        ".classList.contains('ef-no-scroll')"))

    panel = page.evaluate("""() => {
        const r = document.querySelector('.ef-video-dialog-panel').getBoundingClientRect();
        return {w: r.width, h: r.height, cx: r.x + r.width / 2, cy: r.y + r.height / 2};
    }""")
    check("the panel holds the video's 16/9 shape",
          abs(panel["w"] / panel["h"] - 16 / 9) < 0.02)
    check("the panel is centred",
          abs(panel["cx"] - 640) < 3 and abs(panel["cy"] - 450) < 3)

    section("Escape closes it, and the audio stops")
    page.keyboard.press("Escape")
    page.wait_for_timeout(700)
    check("the dialog closed",
          page.evaluate("() => !document.querySelector('%s').open" % DIALOG))
    check("the video was paused",
          page.evaluate("() => document.querySelector('%s').paused" % VIDEO))
    check("and rewound, so the next open starts at the beginning",
          page.evaluate("() => document.querySelector('%s').currentTime === 0" % VIDEO))
    check("the scroll lock was released",
          page.evaluate("() => !document.documentElement"
                        ".classList.contains('ef-no-scroll')"))
    # <dialog> does this by itself. Asserted because replacing it with a
    # hand-rolled modal would silently lose it.
    check("focus returned to the button that opened it",
          page.evaluate("() => document.activeElement.matches('a[data-video-open]')"))

    section("The poster in How it Works opens the same dialog")
    page.click(".ef-video-trigger")
    page.wait_for_timeout(900)
    check("it opened",
          page.evaluate("() => document.querySelector('%s').open" % DIALOG))
    check("there is still only one video element on the page",
          page.evaluate("() => document.querySelectorAll('video').length === 1"))

    section("Clicking the backdrop closes it")
    # The dialog element IS the backdrop, so a click landing on it rather than
    # on the panel is a click outside.
    page.mouse.click(20, 20)
    page.wait_for_timeout(700)
    check("it closed",
          page.evaluate("() => !document.querySelector('%s').open" % DIALOG))
    check("that also paused the video",
          page.evaluate("() => document.querySelector('%s').paused" % VIDEO))

    section("The close button closes it")
    page.click(".ef-video-trigger")
    page.wait_for_timeout(600)
    page.click("[data-video-close]")
    page.wait_for_timeout(600)
    check("it closed",
          page.evaluate("() => !document.querySelector('%s').open" % DIALOG))

    page.close()

    section("A narrow screen")
    phone = browser.new_page(viewport={"width": 390, "height": 844})
    phone.goto(BASE + "/index.php", wait_until="load")
    phone.wait_for_timeout(500)
    # 18 November 2026: the sideways scroll is fixed, so this check is widened
    # from the video to the whole page, as the note here used to say to do.
    #
    # It was 123px at 390px wide and was blamed on the <pre class="ef-formula">
    # and the Bloom weights table in the Methodology card. Both were innocent.
    # .ef-row sets align-items: flex-start, which is right for a row and means
    # "shrink to fit content" once the row flips to a column at 860px, so the
    # widest content set the width of the page. The tell was that the overflow
    # did not shrink with the screen: 513 at 390, 511 at 360, 511 at 320. A real
    # content overflow tracks the viewport. One declaration restores stretch.
    #
    # Checked at three widths for that reason. A single width would pass again
    # on a layout that merely has a floor just under 390.
    for width in (390, 360, 320):
        phone.set_viewport_size({"width": width, "height": 844})
        phone.wait_for_timeout(250)
        check("the landing page does not scroll sideways at %dpx" % width,
              phone.evaluate("w => document.documentElement.scrollWidth <= w + 1",
                             width))
    phone.set_viewport_size({"width": 390, "height": 844})
    phone.wait_for_timeout(250)

    check("the poster does not overflow the screen",
          phone.evaluate("""() => {
              const r = document.querySelector('.ef-video-trigger').getBoundingClientRect();
              return r.left >= 0 && r.right <= document.documentElement.clientWidth;
          }"""))
    phone.click(".ef-video-trigger")
    phone.wait_for_timeout(2500)
    check("the dialog opens on a phone",
          phone.evaluate("() => document.querySelector('%s').open" % DIALOG))
    check("playback starts there too",
          phone.evaluate("() => !document.querySelector('%s').paused" % VIDEO))
    fits = phone.evaluate("""() => {
        const r = document.querySelector('.ef-video-dialog-panel').getBoundingClientRect();
        return r.left >= 0 && r.right <= document.documentElement.clientWidth;
    }""")
    # is_mobile emulation is deliberately NOT used. Driving a full Chrome with
    # it makes window.innerWidth disagree with documentElement.clientWidth,
    # which made this measurement look like a bug that was not there.
    check("the panel fits inside the screen", fits)
    phone.close()

    section("Reduced motion")
    calm = browser.new_page(viewport={"width": 1280, "height": 900},
                            reduced_motion="reduce")
    calm.goto(BASE + "/index.php", wait_until="load")
    calm.wait_for_timeout(500)
    calm.click("a.ef-btn-ghost[data-video-open]")
    calm.wait_for_timeout(700)
    check("the dialog still opens",
          calm.evaluate("() => document.querySelector('%s').open" % DIALOG))
    # The badge is centred WITH a transform, so the blanket "transform: none"
    # in the reduced-motion block has to be overridden for it or it lands in
    # the corner.
    check("the play badge is still centred, not thrown into the corner",
          calm.evaluate("""() => {
              const b = document.querySelector('.ef-video-play');
              const t = getComputedStyle(b).transform;
              return t !== 'none' && t.includes('matrix');
          }"""))
    calm.close()

    section("Overall")
    check("no JavaScript errors at any point", not errors)
    if errors:
        print("        " + repr(errors[:4]))

    browser.close()

print("\n=============================")
print("Passed: %d   Failed: %d" % (passed, len(failures)))
if failures:
    print("\nFailed checks:")
    for f in failures:
        print("  - " + f)
sys.exit(1 if failures else 0)
