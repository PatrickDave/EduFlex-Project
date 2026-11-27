# Deploying EduFlex to a public website

Written 27 November 2026, for a final oral defense of 4 to 12 December 2026.

**Read section 1 before doing anything.** Deploying is not just a hosting
choice for this project; it changes who can reach participant data, and that is
an ethics question your adviser should answer, not a technical one.

---

## 1. Three things to settle first

### 1a. Participant data moves onto someone else's server

`privacy.php` doubles as the participant information sheet, and it tells
participants that the project team can read the database. On a free shared
host, the hosting company can read it too, and it is a company nobody consented
to.

**This is not a reason not to deploy.** It is a reason to decide deliberately.
Three options, any of which is defensible:

1. **Deploy empty.** A fresh database, no real participant rows. Accounts
   created on the public site are demo accounts. Your real study data stays on
   the laptop. **This is the safest and it is what I would do.**
2. **Deploy with data, and update `privacy.php`** to name the host, before any
   participant signs up.
3. **Ask the adviser.** If the ethics submission says where data is stored,
   that submission is what a reviewer holds you to.

### 1b. The Gemini free tier is 20 requests per day, shared

Right now the quota is spent by one person on one laptop. On a public site
every visitor who asks the companion a question spends from the same 20.

A panel member trying the live site the morning of your defense could exhaust
it before you present. **Consider leaving `AI_DRIVER` set to `mock` on the
public site** and demonstrating the real provider from the laptop. The mock is
deterministic and costs nothing, and the public site then cannot be broken by
strangers.

### 1c. The laptop stays the demo machine

Do not swap your defense demo to the public site. The laptop works with no
wifi, which is the whole reason rule 3 exists. The public site is for
accessibility, for your adviser to click, and for the manuscript to cite. Keep
both.

---

## 2. Which host

EduFlex needs four things, and the fourth is what eliminates most free tiers:

| Need | Why |
|---|---|
| PHP 8.x | the whole application |
| MySQL / MariaDB | everything is stored there |
| Outbound HTTPS | `includes/ai.php` calls the provider with cURL |
| Outbound SMTP on port 587 | email verification, via PHPMailer |

Vercel, Netlify and GitHub Pages are static hosts. They cannot run PHP at all.
Render and Railway can, through Docker, but their free tiers give no persistent
disk, so uploaded material disappears on restart.

### Option A: InfinityFree. Free, fastest, good enough.

Free PHP 8 and MySQL, a free subdomain, and free SSL. No card.

Confirmed from their own support forum: **outbound SMTP works on port 587 with
STARTTLS**, which is exactly what `config/mail.php` already uses, and external
APIs are allowed except a specific blocklist. Ports 25 and 2525 are blocked,
and 465 is disputed, so stay on 587.

**One file will not fit.** InfinityFree caps most files at 10 MB and
HTML/PHP/JS at 1 MB. Checked against this repository:

- Every `.php` and `.js` file is under 1 MB. Fine.
- `assets/video/eduflex-explainer.mp4` is **15.7 MB and will be rejected**.
  Worse, their uploader can report success and delete it afterwards.

See section 5 for what to do about the video.

Everything else fits: 175 files, 17.9 MB in total.

### Option B: GitHub Student Developer Pack. Better, slower to set up.

You are a student at the University of Cebu, so you qualify. The pack includes
a **free `.me` domain for a year from Namecheap** with a free SSL certificate,
and DigitalOcean credit (sources differ between $100 and $200; check GitHub's
own page). A DigitalOcean droplet is a real server: no file size caps, no
blocked ports, full control, and a proper domain rather than a subdomain.

Two honest warnings:

- **Verification takes time.** GitHub asks for proof of enrolment and approval
  is not instant. With the defense 7 to 15 days away, start today or do not
  rely on it.
- DigitalOcean usually wants a small card pre-authorisation, and the droplet
  bills normally once the credit runs out. Set a calendar reminder.

### Recommendation

**Start InfinityFree today. Apply for the Student Pack in parallel.**

InfinityFree gets you a working public URL this week, which is what your
adviser actually wants to click. If the Student Pack comes through in time, you
move to a nicer domain with the same files. If it does not, you have lost
nothing.

---

## 3. Deploying to InfinityFree

1. Sign up at `infinityfree.com` and create an account.
2. Create a site. Take the free subdomain, for example
   `eduflex.infinityfreeapp.com`. Write down the **account id** (it looks like
   `epiz_00000000`); the database name and user are both prefixed with it.
3. In the control panel, **MySQL Databases**, create a database. Note the four
   values: host, database name, username, password. **The host is not
   `localhost`.** It is an address like `sql123.infinityfree.com`, and using
   `localhost` fails with an error that reads as though the database is down.
4. **Import the schema.** Open phpMyAdmin from the control panel and import
   `database/01_schema.sql`.

   That file opens with `DROP DATABASE`, which is correct here because the
   database is empty and wrong anywhere else. It may need the
   `CREATE DATABASE` and `USE` lines removed, since shared hosts create the
   database for you and do not let you make another.

5. **Upload the files over FTP** (FileZilla; the control panel shows the
   credentials). Everything goes inside `htdocs`. Skip these:

   ```
   .git/            tests/           android/        docs/
   Claude outputs/  storage/uploads/ storage/avatars/
   *.md             run_tests.sh     build_pages.py
   ```

   None of them are needed to serve the site, and `tests/` and `android/`
   are the bulk of what is left after the video.

6. **Create the three config files on the server.** They are gitignored, so
   FTP will not have brought them:

   - `config/database.local.php` from `config/database.example.php`, with the
     four values from step 3.
   - `config/ai.php` from `config/ai.example.php`. Set `AI_DRIVER` to `mock`
     unless you have decided otherwise; see section 1b.
   - `config/mail.php` from `config/mail.example.php`, port 587.

7. **Make the storage directories writable.** Create `storage/uploads` and
   `storage/avatars` over FTP and set them to 755. `storage/.htaccess` must be
   uploaded with them: it is what stops Apache serving an uploaded file as
   code.

8. **Turn on SSL** in the control panel and wait for the certificate. Then
   visit over `https://`. `security_is_https()` detects it and switches on the
   Secure cookie flag and HSTS by itself, with no code change. That closes
   "Still to do" item 3.

9. **Create the team account** through the normal Sign Up form, then grant it:

   ```
   UPDATE user SET is_admin = 1 WHERE email = 'eduflex54@gmail.com';
   ```

   `tools/make_admin.php` is a command line script, and shared hosting gives
   you no shell, so phpMyAdmin is the way here.

---

## 4. Checking it worked

Run `php tools/deploy_check.php` locally before you upload. It checks the PHP
version, the extensions, whether the config files exist, whether storage is
writable, and whether outbound HTTPS and SMTP are reachable. It never prints a
password or a key, only whether one is set.

Shared hosting gives you no shell, so to run it on the server copy it to the
web root and open `https://your-site/deploy_check.php?run=1`. The `?run=1` is
required so a crawler cannot trigger it. **Delete it as soon as you have read
it**: it reports which config files exist and which database it reached, and
that is a map nobody outside the team needs.

`tools/.htaccess` denies the whole directory, so leaving the scripts there
after an FTP upload is safe. Do not weaken that file to reach the checker in
place; copy it out, as above.

By hand, in this order, because each one tells you something different:

1. The landing page loads and is styled. If it is unstyled, the asset paths or
   the upload are wrong.
2. Sign up. If this fails, the database credentials are wrong.
3. The verification email arrives. If not, SMTP is blocked or misconfigured;
   see section 1b of this file and the Spam section in CLAUDE.md.
4. Upload a small `.txt`. If this fails, `storage/uploads` is not writable.
5. Ask the companion a question. With `mock` this always works. With a real
   key, a failure here means outbound HTTPS is blocked.
6. Open the site on a phone. The pages are responsive and tested at 320, 360
   and 390 pixels.

---

## 5. The explainer video

15.7 MB against InfinityFree's 10 MB cap. Four options, worst to best:

1. **Leave it out.** The landing page keeps its poster image and the dialog
   opens onto a video that 404s. Do not do this: a broken player in front of a
   panel is worse than no player.
2. **Remove the player from the deployed copy only.** Honest and tidy, but it
   edits `index.php` on the server, so the deployed copy drifts from the repo.
3. **Compress it below 10 MB.** 3:18 at 1920x1080 compresses well; 720p at a
   lower bitrate would land around 6 to 8 MB. **There is no ffmpeg on this
   machine**, so this needs installing it or using an online converter. This is
   the right answer if you have an hour.
4. **Put it on Google Drive or YouTube and link out.** Note that the CSP is
   `default-src 'self'`, so an embedded iframe would be blocked by the browser.
   A plain link that opens in a new tab works and breaks nothing.

**Keep the 15.7 MB original in the repository either way.** The laptop demo is
the one that has to work with no wifi, `tests/manuscript_test.php` asserts the
file exists, and that test should keep passing.

---

## 6. What a public deployment does not change

- The laptop demo. Keep rehearsing it.
- The Android app. It points at whatever address you give it, so it can point
  at the public site instead of `adb reverse`, which removes the cable from the
  demo entirely. Worth testing: set the server address to
  `https://your-site/` in the app's menu. **This is the one genuinely large
  win from deploying**, and it needs no rebuild.
- The rubric run. Still only runnable by Patrick, still the last thing missing
  from Chapter IV, and it should be run against the laptop, not the public
  site.
