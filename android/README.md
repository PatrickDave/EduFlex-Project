# EduFlex for Android

A WebView over the same pages Apache already serves. Written 18 November 2026,
for the final oral defense of 4 to 12 December 2026.

**This project has not been compiled.** It was written on a machine with no
JDK, no Android SDK, no Gradle and no Android Studio, so nothing here has been
through a compiler or run on a device. Every XML file is checked well-formed
and every `R.` reference is checked to resolve, which catches typos but is not
the same as a build. Budget an hour for the first build and do it well before
December, not on the day.

---

## 1. Why a wrapper and not a native app

The manuscript's technology stack says mobile application. This is one, and it
is the right kind for this system.

EduFlex is PHP. PHP runs on a server, not on a handset. A native Android
client would mean rebuilding all seventeen screens in Kotlin and turning every
file in `includes/` into a REST API. That is months of work, it would add a
second copy of the mastery rules to keep in step with the first, and none of it
would improve the thing the study is actually about.

The wrapper gives you the installable `.apk` a panel expects, on top of one
codebase. When a page changes, the app changes, with nothing to rebuild.

What makes this defensible rather than a shortcut: the pages are genuinely
responsive and that is tested, not asserted. `video_browser_test.py` checks the
landing page at 320, 360 and 390 CSS pixels, and `runner_browser_test.py` and
`chat_browser_test.py` both check that practice and the companion do not scroll
sideways at 390.

---

## 2. What to install

One thing: **Android Studio**, from `developer.android.com/studio`. It brings
its own JDK, its own Gradle and the SDK manager. Do not install a JDK
separately; a mismatched one is the usual cause of a first build failing.

About 1 GB. The notes record that large downloads time out on this connection,
so start it when you have time and do not leave it until December.

During the setup wizard, accept the default SDK. Then open **SDK Manager** and
confirm **Android 14 (API 34)** is ticked, because that is what
`app/build.gradle` compiles against.

---

## 3. Building the APK

1. Android Studio, **Open**, choose the `android` folder inside the project.
   Not the project root, the `android` folder.
2. Wait for Gradle to sync. The first sync downloads the plugin and takes a
   while. If it asks about a missing Gradle wrapper, let it use its own.
3. **Build** menu, **Build Bundle(s) / APK(s)**, **Build APK(s)**.
4. The notification links to the file. It is at:

```
android/app/build/outputs/apk/debug/app-debug.apk
```

Copy that to the phone and open it. Android will ask you to allow installing
from unknown sources, which is normal for an app that did not come from Play.

### If you want a release build

You do not need one. `assembleDebug` signs with a local debug key, installs
fine and is what you should demo. A release build needs a keystore you create
and must not lose, and buys nothing for a defense.

---

## 4. Connecting the phone to XAMPP

**This is the part that fails on the day if it is not rehearsed.** The app runs
on the phone; EduFlex runs on the laptop. The phone has to reach the laptop.

The app asks for the address on first launch and remembers it. Change it any
time from the menu, top right, without rebuilding.

### Option A: USB cable. Use this one.

Works with no wifi at all, which matters because rule 3 exists precisely so the
system works in a room with no network.

On the phone: Settings, About, tap Build number seven times, then Developer
options, enable **USB debugging**. Plug in the cable and accept the prompt.

On the laptop, in PowerShell:

```powershell
cd $env:LOCALAPPDATA\Android\Sdk\platform-tools
.\adb devices                      # should list your phone
.\adb reverse tcp:8080 tcp:80      # phone's localhost:8080 -> laptop's port 80
```

In the app, use:

```
http://localhost:8080/eduflex-ui/
```

**`adb reverse` does not survive unplugging the cable.** If the app stops
reaching the server, that is almost always why. Run it again.

### Option B: same wifi

Find the laptop's address with `ipconfig` (the IPv4 address, something like
`192.168.1.14`). In the app use `http://192.168.1.14/eduflex-ui/`.

Two things usually block this:

- **Windows Firewall** drops inbound port 80 by default. Allow it, or it looks
  like the phone is at fault when it is not.
- **XAMPP may be bound to localhost only.** If `httpd.conf` says
  `Listen 127.0.0.1:80`, nothing outside the laptop can connect. It needs to be
  `Listen 80`.

### Option C: emulator

`http://10.0.2.2/eduflex-ui/`, which is how the emulator addresses its host. No
cable, no wifi, but a slower demo and it is not a real phone in a panel's hand.

---

## 5. What the wrapper actually adds

A bare WebView would show the pages and break five things. Each of these is
implemented and commented in `MainActivity.java`:

| Feature | Without it |
|---|---|
| `CookieManager.flush()` in `onPause` | The seven-day browser trust is lost every launch, so email verification returns on every sign-in |
| `onShowFileChooser` | Upload Material does nothing at all, silently |
| MIME mapping for `accept=".pdf,.docx,.txt,.md"` | The picker opens with every document greyed out |
| `DownloadListener` | Export my data does nothing, and that is the withdrawal mechanism Chapter III cites |
| `tel:` and `mailto:` handoff | The Who to Contact block is dead text |

The cookie flush deserves the emphasis it gets in the source. The trusted
browser bug was found and fixed in the web app on 2 November; a wrapper without
that one line puts it straight back, on the phone, where it looks like a new
bug.

The MIME mapping is the subtle one. EduFlex writes **file extensions** in its
`accept` attributes, and Android's `EXTRA_MIME_TYPES` only understands MIME
types. Passing `.pdf` through matches nothing, with no error anywhere.

---

## 6. Deliberate decisions

- **No dependencies at all.** Not AndroidX, not Material. The app uses
  `android.app.Activity`, the framework `AlertDialog` and the framework
  `WebView`, so the only thing Gradle must download is the plugin. On a
  connection where downloads time out, that is the difference between building
  first time and losing an afternoon.
- **Java, not Kotlin.** One less version pairing to get right, and the team can
  read it.
- **`targetSdk 34`, not the newest.** This APK is sideloaded for a defense and
  never published, so there is no store deadline and a conservative target is
  one less variable.
- **`minSdk 24`** (Android 7.0), which covers essentially any handset a panel
  member will be holding.
- **Cleartext HTTP is allowed**, and `res/xml/network_security_config.xml`
  explains exactly why and what to delete when EduFlex is served over HTTPS.
  Without it, Android 9+ blocks every request and the WebView is blank with no
  stated reason.

---

## 7. Not done

Stated plainly, because the panel may ask and you need to know what you can
claim.

1. **Never compiled or run.** See the top of this file.
2. **No offline mode.** Close the laptop and the app shows the "Cannot reach
   EduFlex" page. Caching pages would mean caching a learner's material on the
   handset, which is a privacy question `privacy.php` does not currently cover,
   so it was left out rather than added quietly.
3. **No push notifications.** The bell in the app is the web one. Real push
   needs Firebase, which is an external network service and would contradict
   rule 3.
4. **Not on the Play Store**, and not intended to be. Sideloaded only.
5. **No app-specific tests.** The pages it displays are covered by the existing
   PHP and Playwright suites; the wrapper itself is not.
