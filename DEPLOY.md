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

**Everything fits, since 27 November.** InfinityFree caps most files at 10 MB
and HTML/PHP/JS at 1 MB. Checked against this repository:

- Every `.php` and `.js` file is under 1 MB.
- `assets/video/eduflex-explainer.mp4` was 15.3 MB and would have been
  rejected. It is now **8.6 MB** and fits. See section 5.

Total: 175 files, about 11 MB.

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
4. **Import the schema.** Open phpMyAdmin from the control panel, **select your
   database in the left sidebar**, then Import → `database/01_schema_shared_host.sql`
   → Go.

   **Import that file, not `01_schema.sql`.** The original opens with
   `DROP DATABASE`, `CREATE DATABASE` and `USE`, which are right for a fresh
   install on a laptop and refused by a shared host, which creates the one
   database you may have and disables those statements. Importing the original
   gives a green "queries executed" banner and a red
   `"DROP DATABASE" statements are disabled` error at the same time. Both are
   true: every table is created and the first line fails. The shared-host file
   imports with no error at all.

   Selecting the database first matters. With none selected, every
   `CREATE TABLE` fails with "No database selected".

   `01_schema_shared_host.sql` is generated from `01_schema.sql`, so the two
   cannot disagree:

   ```
   php tools/make_shared_schema.php           # regenerate
   php tools/make_shared_schema.php --check    # is it in step?
   ```

   `tests/admin_test.php` fails if they drift.

   **Do not import `02_migrations.sql` afterwards.** A fresh import already
   contains every change in it. Verified: importing the shared-host file into
   an empty database and running `php tools/migrate.php` reports "Nothing to
   do. This database is up to date." That file is only for a database that
   already holds data.

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

8. **SSL needs no action on a free subdomain.** The SSL page says "Custom SSL
   certificates are no longer supported for free subdomains. All free
   subdomains have SSL by default." That is a notice, not an error, and it
   means the certificate is already there. **Do not click Create Order.**

   Just visit `https://your-site/`. `security_is_https()` detects it and
   switches on the Secure cookie flag and HSTS by itself, with no code change,
   which closes "Still to do" item 3. Verified on
   `eduflex-infinity09.infinityfreeapp.com`: the TLS handshake succeeds and
   the certificate validates.

   SSL only needs ordering for a **custom** domain, such as a `.me` from the
   Student Pack.

### If the page is a 500

**Almost certainly an incomplete upload.** This happened on 27 November 2026 on
`eduflex-infinity09`: only the root `.php` files and `tools/` had arrived.
`index.php` requires `includes/security.php` on line 12, that file was not
there, PHP fatalled, and Apache answered 500 with an empty body.

**Find out what is actually missing before re-uploading everything.** Request
one known file from each directory and read the status code. A 404 means the
directory never arrived; 200, 403 or 500 all mean it is there:

| Request | Tells you about |
|---|---|
| `/includes/helpers.php` | `includes/` |
| `/config/database.php` | `config/` |
| `/partials/public_footer.php` | `partials/` |
| `/assets/css/eduflex.css` | `assets/css/` |
| `/assets/vendor/jquery-3.7.1.min.js` | `assets/vendor/` |
| `/app/dashboard.php` | `app/` |
| `/tools/deploy_check.php` | `tools/` (403 is correct here) |

A **403** from `config/`, `storage/` or `tools/` is the right answer, not a
problem: those three carry a `.htaccess` that denies the directory. A **404**
from them means the files are not there at all.

Re-upload the missing directories and **check FileZilla's Failed transfers
tab** afterwards. It records a failure and then carries on, so a partial upload
looks finished.

### If the page is blank

A 200 status with an empty body is PHP fatalling with error display off, which
`auth_boot()` turns off on purpose. Nothing reaches the browser, so there is
nothing to read on screen. In order:

1. **Control panel, Error Log.** This is the fast answer and it names the file
   and line. Everything below is guesswork by comparison.
2. **Run the checker.** Copy `tools/deploy_check.php` to the web root and open
   `?run=1`. Its "Files the landing page needs" section catches an incomplete
   upload, which is the most common cause: FileZilla logs a failed transfer and
   then carries on, so a partial upload looks finished.
3. **Check where the files landed.** They belong directly in `htdocs`, so that
   `htdocs/index.php` exists. Uploading the project folder itself puts
   everything one level too deep and the site root is then empty.
4. **Check `config/` exists on the server** with `database.local.php` in it.
   A missing `config/ai.php` does NOT break the landing page; nothing on the
   public path reads it, and `legal.php` guards `AI_DRIVER` with `defined()`.

A blank page is not a damaged database. The import and the upload are separate
steps and an empty page says nothing about the tables.

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

**Solved on 27 November. Upload it with everything else.**

Patrick needs the explainer on the public site, because the adviser wants to
glance at the work without being in the room. It was 15.3 MB against a 10 MB
cap, so it was re-encoded to **8.6 MB**, and it fits.

What was done, in case it ever needs repeating:

```
ffmpeg -y -i in.mp4 -c:v libx264 -preset slow -b:v 290k -pass 1 -an -f mp4 NUL
ffmpeg -y -i in.mp4 -c:v libx264 -preset slow -b:v 290k -pass 2 \
       -profile:v high -level 4.0 -pix_fmt yuv420p -movflags +faststart \
       -c:a aac -b:a 64k -ac 1 out.mp4
```

- **Still 1920x1080 and still 3:18.** Nothing was cropped or cut. Downscaling
  to 720p was rejected: the video is full of UI screenshots with small text,
  and that text is the first thing a rescale destroys.
- **Two-pass, not CRF**, because the target here is a file size, and CRF
  targets a quality level and lands wherever it lands.
- **Audio went from 130 kb/s stereo to 64 kb/s mono.** It is a single
  narrator, so stereo was carrying nothing, and that paid for most of the
  saving on its own.
- **`-movflags +faststart`** puts the index at the front, so the video starts
  playing before it has finished downloading. That matters more on a shared
  host than on localhost.
- Checked by eye, not just by size: frames at 12s, 75s and 150s compared
  against the original. The small UI text and the burned-in captions are still
  legible. `tests/video_browser_test.py` passes all 31 checks, including that
  playback actually starts.

**There is an ffmpeg on this machine** despite the older note saying otherwise,
bundled with a VS Code extension at
`%USERPROFILE%\.vscode\extensions\kilocode.kilo-code-*\bin\ffmpeg.exe`.

**The 15.3 MB master is still in `Claude outputs\Eduflex-Explainer v2.mp4`**,
which is gitignored and local only. Re-encode from that, never from the 8.6 MB
copy, or each pass loses more.

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
