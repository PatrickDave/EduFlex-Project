# Setup — read this first

Fifteen minutes, start to finish. Follow it in order.

## 1. Install XAMPP

Download from apachefriends.org and install with the defaults. You need Apache
and MySQL. You do not need FileZilla, Mercury or Tomcat.

## 2. Put the project in place

Copy the whole `eduflex-ui` folder to:

    C:\xampp\htdocs\eduflex\

You should end up with `C:\xampp\htdocs\eduflex\index.php`. If you see
`C:\xampp\htdocs\eduflex\eduflex-ui\index.php`, you nested it one level too
deep. Move the files up.

## 3. Start the servers

Open XAMPP Control Panel and click **Start** next to **Apache** and **MySQL**.
Both should turn green.

If Apache refuses to start, something else is using port 80. Skype and IIS are
the usual culprits. In XAMPP click Config next to Apache, open `httpd.conf`,
change `Listen 80` to `Listen 8080`, and use `http://localhost:8080/` below.

## 4. Create the database

1. Go to `http://localhost/phpmyadmin/`
2. Click the **Import** tab at the top
3. Click **Choose File** and pick `database/01_schema.sql`
4. Scroll to the bottom and click **Go**

You should see a green success message and an `eduflex` database in the left
sidebar with 13 tables.

The script starts with `DROP DATABASE IF EXISTS eduflex`, so re-importing wipes
and rebuilds it. That is handy while developing, and dangerous later. Remove
that line once you have data you care about.

## 5. Check the connection settings

Open `config/database.php`. The defaults match a stock XAMPP install:

    DB_USER = 'root'
    DB_PASS = ''

If you set a MySQL root password during installation, put it in `DB_PASS`.

## 6. Try it

Go to `http://localhost/eduflex/`

1. Click **Get Started for Free**
2. Fill in your name, email and a password of at least 8 characters
3. Tick the terms box and click **Sign Up**
4. You land on the login page with a green "Account created" banner
5. Log in
6. The dashboard greets you by your first name

That name comes from the `user` table. If you see it, the whole chain works:
Apache, PHP, MySQL, the schema, password hashing and sessions.

To confirm in phpMyAdmin, open the `eduflex` database and click the `user`
table. You will see your row, and `password_hash` will be an unreadable string
beginning with `$2y$`. That is correct. Passwords are never stored readable.

## 7. Upload your first document

Open **AI Learning Companion** in the sidebar. The library lives inside it, on
the right. Attach a PDF, DOCX or TXT with the paperclip in the message box, or
drag a file onto the drop zone in the library panel.

There is no separate Materials item in the navigation any more: you upload and
ask about a document in the same place. `materials.php` still exists as a
full-width management page and is linked from the library panel once you have
more than four files.

EduFlex saves the file, extracts the text, and splits it into chunks of about
700 words. The companion reports back in the conversation when it has finished
reading, and the file appears in the library panel with an Analyzed badge.

A 155-page PDF takes a few seconds and produces roughly 200 chunks.

On a narrow screen the library becomes a slide-over. Tap **Library** in the
header to open it, and the close button, the scrim or the Escape key to shut it.

Three things are rejected on purpose, and all three are worth demonstrating to
your panel:

- A file that is not really what its extension claims. Rename a .exe to .pdf
  and it is refused, because the leading bytes are checked, not the name.
- A scanned PDF. Those pages are images, so there is no text to read. You get
  a clear message rather than an empty document.
- Anything over 20 MB.

### If PDF text comes out garbled

The built-in PDF reader decodes most documents correctly, but some use font
encodings it cannot fully resolve. When that happens you get a warning on the
row rather than silent nonsense.

Two fixes, either works:

    composer require smalot/pdfparser

Run that in the project folder and the better library is picked up
automatically, no code change. Or upload the document as .docx instead, which
is read perfectly and needs nothing extra.

## 8. Turn on topic detection

Out of the box `config/ai.php` uses the **mock** driver: no key, no network, no
cost. Upload a document, click **Detect topics** on its row, and the whole
pipeline runs with invented output. Use this to build and demo without spending
quota.

When you are ready to use your real provider, open `config/ai.php` and set four
things:

    const AI_DRIVER  = 'openai-compatible';   // or 'gemini'
    const AI_API_KEY = 'your-key-here';
    const AI_BASE_URL = 'https://...';        // openai-compatible only
    const AI_MODEL   = 'the model name';

Then choose **Find topics**, either on the material's card in the library panel
or on the message the companion posts after reading a file. It reads the
document and writes one row per topic into `topic_progress`.

**The key belongs in this file and nowhere else.** Never put it in JavaScript,
never commit it. If you use GitHub, add `config/ai.php` to `.gitignore` before
your first push.

### How much quota one document costs

Your 155-page manuscript produces 205 chunks. Sending all of them would be 205
requests for one upload, which would exhaust a free tier immediately.

EduFlex instead samples 12 chunks spread evenly across the document, so topics
reflect the end as well as the beginning. One document costs 12 requests, not
205. Change `AI_MAX_CHUNKS_PER_RESOURCE` in `config/ai.php` if your quota allows
finer coverage.

Every call is recorded in the `ai_interaction` table. That is your cost record,
your latency record, and your evidence for Chapter IV. Check it in phpMyAdmin
after your first run.

### If detection fails

The button turns into **Retry** and shows the reason underneath.

- "The API key was rejected" — wrong key, or wrong base URL for that key.
- "The provider rate limit was reached" — free tier throttling. EduFlex already
  retried three times with increasing waits. Wait a minute and retry.
- "Could not reach the AI provider" — no internet, or a firewall blocking it.

## 9. Generate a practice set

Once a material has topics, open **Practice**. Every topic is listed weakest
first, with the Bloom level EduFlex would use next, and a Generate button.

One press is one request to your provider and produces 8 questions. Sets are
stored and reused, so generating the same topic twice is a deliberate choice
rather than something that happens on its own.

### What gets thrown away, and why

Generated questions are validated before anything is stored. A question is
discarded if:

- the stated answer is not one of its own options
- there are not exactly 4 options
- two options are identical
- it uses "all of the above" or "none of the above"
- it repeats a question already in the same set
- the question text is missing or trivially short

The first of those is the one that matters most. A question whose answer a
learner cannot select would mark them wrong for being right, and that would
corrupt their mastery score permanently. If a set produces fewer than 4 usable
questions, the whole set is rejected and nothing is stored.

When some questions are discarded, the interface says how many. That number is
worth watching: it is a direct measure of your provider's output quality, and
it belongs in your Chapter IV AI-output validation.

### How the Bloom level is chosen

It is derived from your last completed attempt on that topic, not stored:

- no attempt yet, start at Remember
- last score 85 or above, step up one level
- last score below 60, step down one level
- otherwise hold

The same thresholds as the mastery model, so the two cannot disagree.

## 9b. If you clone this from GitHub

`config/ai.php` is deliberately not in the repository, because it holds the API
key. A fresh clone has to create it:

    Windows:    copy config\ai.example.php config\ai.php
    Mac/Linux:  cp config/ai.example.php config/ai.php

Then open your copy and set `AI_DRIVER` and `AI_API_KEY`. Without this step every
page fails with an undefined constant.

## 9b. Updating an existing database (do not reimport)

`database/01_schema.sql` begins with `DROP DATABASE`. That is correct for a first
install and it is how a database full of your accounts, uploads and mastery
scores gets wiped by somebody trying to add one column.

Once you have data you care about, apply schema changes this way instead:

    C:\xampp\php\php.exe tools\migrate.php            (show what is missing)
    C:\xampp\php\php.exe tools\migrate.php --apply    (add it)

It prints how many accounts, materials, topics and attempts the database holds
before it does anything, lists each change and whether it is already present, and
adds only what is missing. It never drops a table, never drops a column and never
deletes a row. Running it against an up-to-date database reports "nothing to do".

`database/02_migrations.sql` is the same set of changes as plain SQL, if you
would rather paste it into phpMyAdmin.

Reimport `01_schema.sql` only when you genuinely want to start over.

## 10. Run the test suite (optional but useful)

Open a terminal in the project folder:

    C:\xampp\php\php.exe tests\auth_test.php
    C:\xampp\php\php.exe tests\extract_test.php
    C:\xampp\php\php.exe tests\ai_test.php
    C:\xampp\php\php.exe tests\questions_test.php
    C:\xampp\php\php.exe tests\attempts_test.php
    C:\xampp\php\php.exe tests\chat_test.php
    C:\xampp\php\php.exe tests\notifications_test.php
    C:\xampp\php\php.exe tests\support_test.php
    C:\xampp\php\php.exe tests\settings_test.php
    C:\xampp\php\php.exe tests\security_test.php
    C:\xampp\php\php.exe tests\avatar_test.php
    C:\xampp\php\php.exe tests\rubric_test.php
    C:\xampp\php\php.exe tests\legal_test.php

Expect `Passed: 40`, `32`, `63`, `73`, `81`, `69`, `77`, `39`, `60`, `79`, `87`,
`69`, `76` and `36`, all with `Failed: 0`, for 881 checks in total. On Mac or Linux,
`./run_tests.sh` runs all fourteen. None needs a database, a web server, an API
key or an internet connection.

One exception to that. 5 of the 32 extraction checks build a .docx fixture,
which needs PHP's `zip` extension. If you see

    SKIP  DOCX extraction: the PHP zip extension is not enabled.

then open `C:\xampp\php\php.ini`, find the line `;extension=zip`, remove the
leading semicolon, and restart Apache. The suite passes either way, but without
that extension **every DOCX upload fails at runtime as well**, so fix it now
rather than during a defense. Expect `Passed: 27` until you do.

`tests/attempts_test.php` is the one to point a panel at. It contains the
mastery arithmetic worked out by hand: that a recent correct answer scores
53.49 against 46.51 for a recent wrong one, and that the same answer is worth
71.67 at Create but 34.33 at Remember. If the formula in Chapter III is ever
changed, that file is where the change has to be proved.

`tests/chat_test.php` is the one to point a panel at for the companion. It
proves the grounding rules: that a question the material does not answer is
refused rather than filled in from the model's own knowledge, that a citation to
a passage the model was never given is discarded, and that one learner can never
read another learner's conversation.

`tests/settings_test.php` is the one to point a panel at on research ethics. It
proves that a participant can withdraw: that Delete my account removes every row
belonging to that learner, across every table that holds their data, and not one
row belonging to anybody else. It runs with SQLite foreign keys switched on, so
the cascade is really exercised rather than assumed.

`tests/security_test.php` is the one to point a panel at on hardening. Its first
block is a table of twenty-three redirect destinations and whether each is
followed, including `/\evil.example.com/phish`, which the previous check let
through and which really did produce an off-site `Location` header on the running
system. See README section 1c for the account of it.

There are also two browser tests, which need a running Apache and MySQL and
`pip install playwright`:

    python3 tests/runner_browser_test.py    54 checks, practice end to end
    python3 tests/chat_browser_test.py      29 checks, the companion end to end

Both drop and recreate the `eduflex` database, so do not run them against data
you want to keep.

---

## Troubleshooting

**"The system cannot reach the database right now"**
MySQL is not running. Start it in XAMPP Control Panel.

**"Unknown database 'eduflex'"**
Step 4 did not complete. Re-import `database/01_schema.sql`.

**The page shows PHP code instead of a web page**
You opened the file by double-clicking instead of going through
`http://localhost/`. PHP only runs when Apache serves it.

**Blank white page**
PHP hit a fatal error. Open `C:\xampp\apache\logs\error.log` and read the last
few lines.

**Styles are missing, everything looks like plain text**
The CSS path is wrong, which usually means the folder is nested one level too
deep. See step 2.

**Access denied for user 'root'@'localhost'**
Your MySQL root password does not match `DB_PASS` in `config/database.php`.

---

## What works now, and what does not

Working:

- Registration, with validation and duplicate-email detection
- Login and logout, with sessions
- Every page inside `/app` is gated. Signed out, you get bounced to login.
- Uploading materials from inside the companion chat, with validation, text
  extraction and chunking
- Deleting a material, which removes its file and its chunks
- Editing your profile from Settings
- Topic detection, which reads a material and records what it covers
- Question generation, with validation before anything is stored

**Every screen now reads the database.** There is no sample data left anywhere.
A new account sees an empty state that tells it what to do next, and the
screens fill in as you get further. There are three stages:

1. Nothing uploaded. Every screen points you at Materials.
2. Uploaded but no practice. Screens say so and point at Practice.
3. Real answers recorded. Screens show actual mastery.

Not working yet, because it belongs to the next phase:

- Answering a set. Sets are generated and stored, but the runner that presents
  the questions and records answers is next.
- Scoring and mastery, which need recorded answers first.
- The AI companion composer is present but disabled until it is connected.

Because the screens are honest about all of this, you can hand the system to
your adviser today without explaining which numbers are real.

Read `AI-OPTIONS.md` before the next phase. It covers running the model at zero
cost and the one sentence in your manuscript that may need changing.
