# Week 7: hardening

**Status: built, 14 September 2026.** This is the record of what was looked at,
what was found, and what was decided. The controls themselves are described in
`README.md` sections 1b and 1c; this file exists so nobody repeats the audit and
so the decisions have a stated reason behind them.

Read `CLAUDE.md` first. The rules this layer added are 10 to 13.

## What was audited

Every part of the request path, by reading it and then by attacking the running
system where an attack was possible:

1. Output escaping across all 11 generated pages and the 3 public pages.
2. CSRF and method guards on all 18 endpoints in `app/actions/`.
3. SQL construction everywhere, including the two `LIMIT` interpolations added by
   the notifications layer.
4. The upload path: extension checks, magic-byte checks, stored filenames, and
   whether Apache will serve or execute anything in `storage/`.
5. Session cookie flags, session fixation, session lifetime.
6. The login form: enumeration, timing, guess rate, and the `next` parameter.
7. Response headers.
8. What a PHP error puts on the screen.

## What was already correct

Recorded because it is worth being able to say so, and because a panel is more
likely to ask about these than about the things that were wrong:

- Output escaping is clean. Every rendered value goes through `e()`. The two uses
  of `nl2br()` wrap `e()` rather than the reverse, which is the order that
  matters.
- All 18 action endpoints check a CSRF token **and** refuse anything that is not
  a POST. Verified by posting to `delete_account.php` with a valid session and no
  token: refused, and the account survived.
- No SQL is built by concatenation anywhere. The `LIMIT` values are clamped and
  cast to `int` before interpolation, and the one `WHERE` fragment that varies
  chooses between two fixed strings.
- `storage/.htaccess` works. An uploaded file fetched directly over HTTP returns
  403, and so does a directory listing.
- Uploads are checked by leading bytes as well as extension, and stored under a
  generated name, so a `.php` renamed to `.pdf` is rejected and a filename from
  the learner's computer never reaches the filesystem.
- Login already gave one message for a wrong password and an unknown email, and
  already spent comparable time on both.

## What was wrong

### 1. An open redirect on the login form. Exploitable, fixed.

The only exploitable defect found. Full account in `README.md` section 1c. In
short: `next` was validated with `!preg_match('#^(https?:)?//#', $candidate)`,
which does not reject `/\evil.example.com`, and browsers read `/\` as `//`. The
running system answered `Location: /\evil.example.com/phish` after a legitimate
sign-in.

Fixed with `auth_safe_redirect_target()`, which allowlists instead of blocklists.
23 cases in `tests/security_test.php`.

### 2. No limit on password guessing. Fixed.

The form accepted guesses as fast as the network allowed. Now throttled per
email-and-address and per address alone, in the `login_attempt` table. Design
points and their reasons are in `database/SCHEMA-NOTES.md` section 6.

Two decisions worth defending out loud:

- **The throttle refuses the correct password too, while the block is live.**
  Verified: five wrong guesses, then the right password, still refused. If it let
  a correct password through, an attacker whose sixth guess happened to be right
  would simply be let in, and the limit would protect nothing.
- **It fails open if its table is missing.** The only realistic cause is a schema
  that has not been reimported, and refusing every login is worse for this
  project than a temporarily absent rate limit. An attacker cannot cause it
  without database access. It is logged loudly. Asserted in the test suite, so it
  is a stated behaviour rather than an accident.

### 3. A timezone bug in the throttle, found by its own tests.

The first version left `attempted_at` to the column default. SQLite's
`CURRENT_TIMESTAMP` is UTC and PHP's `date()` is local, so on this machine
(UTC+8) the rows landed eight hours away from the window they were compared
against and the throttle counted nothing at all. The same mismatch appears on
MySQL whenever PHP and the server disagree about the timezone.

Fixed by writing the timestamp from PHP so one clock does both sides. This is
now rule 13 in `CLAUDE.md`, because the failure is silent: a rate limiter that
counts nothing looks exactly like one that works.

### 4. No security headers. Fixed.

Added in `security_headers()`, called from `auth_boot()` so no page can forget.
The table is in `README.md` 1b. The one worth talking about is the CSP:
`default-src 'self'` makes the browser enforce the no-external-requests rule from
`CLAUDE.md` rule 3, instead of relying on everyone remembering it. If somebody
adds a CDN link by accident it now fails visibly in the console rather than
working on their machine and breaking in a defense room with no wifi.

`'unsafe-inline'` is granted for scripts and styles because the generated pages
carry inline `<script>` blocks and inline `style` attributes. That is a real
weakening: the policy blocks external code, not injected inline code. Say so if
asked. Escaping is what stops injected inline code, and it was audited above.

`index.php` is the one page that does not get its headers from `auth_boot()`. It
is the public landing page and deliberately starts no session for an anonymous
visitor, so it calls `security_headers()` itself in a block above the doctype.
That gap was found by checking the header on every page in turn rather than
assuming one call site covered them all, which is worth doing because a header
sent after output has started is dropped silently.

### 5. Sessions never expired. Fixed.

`logged_in_at` was recorded and never read. Now two limits, both checked on every
request: 2 hours idle, 12 hours absolute. The absolute one is the point. A stolen
cookie that is used constantly never goes idle, so an idle limit alone does not
bound the damage.

### 6. `display_errors` was on. Fixed.

`includes/*.php` are careful never to show a database error to a user, because the
message leaks table and column names. XAMPP ships with `display_errors=On`, which
undid all of that care: any uncaught warning printed the full server path, and
often part of a query, into the page. `security_harden_error_output()` turns it
off and logs instead. The CLI is exempt so the test suites still show their
output, and `EDUFLEX_DEBUG` restores the old behaviour while developing.

### 7. `X-Powered-By: PHP/8.2.12`. Fixed.

Every response announced the exact PHP version, which tells an attacker which
published vulnerabilities to try. `expose_php` can only be turned off in
`php.ini`, which is not in this repository, so the header is removed in code and
the fix travels with it.

### 8. Two controls that did nothing. Removed rather than implemented.

- **"Remember me for 30 days"** on the login form was an unchecked box that
  nothing read. The session cookie expires when the browser closes, so the label
  was simply untrue. A real remember-me needs a second long-lived credential
  table, which is not going in before the freeze. Removed, on the same reasoning
  as the absent notification toggles.
- **"Forgot Password?"** was `href="#"`. There is no password reset, because it
  needs outbound email and the system deliberately has none. The link now says
  "Cannot sign in?" and points at Support, which is the one thing that can
  actually help. Settings has Change password for anyone already signed in.

`Contact Support` in the footer of the three public pages also pointed at `#` and
now points at `app/support.php`.

## Left alone deliberately

**Privacy Policy and Terms of Service are still `href="#"`** on `index.php`,
`login.php` and `register.php`, and the registration form makes a learner tick
"I agree to the Terms and Privacy Policy" for documents that do not exist. For a
study with a consent commitment that is a real gap, and it is listed in
`CLAUDE.md` "Still to do".

It was not fixed here because the fix is to write those documents, and their text
is Patrick's and his adviser's to write. Inventing a privacy policy in code and
presenting it as the study's policy would be worse than the dead link, because a
participant could rely on it.

## Not verifiable here

- **HTTPS.** The `Secure` cookie flag and HSTS are written and tested, and turn
  themselves on when `security_is_https()` returns true. Nothing on this machine
  serves HTTPS, so only the detection logic is exercised, not a real HTTPS
  request.
- **The rate limit under real concurrency.** Two simultaneous requests could each
  read four failures and each allow a fifth, so the effective limit is "about
  five", not exactly five. Fixing that properly needs `SELECT ... FOR UPDATE` and
  a transaction per attempt. Not worth it: the control exists to turn thousands
  of guesses per minute into a handful, and an off-by-one under concurrency does
  not change that.

## Added afterwards: profile pictures

Not part of the hardening pass, but it landed under the same rules and found one
thing worth recording here.

**`getimagesize()` does not prove a file is an image.** Given the 8-byte PNG
signature followed by anything at all, it reports a valid `image/png` and reads
the "dimensions" straight out of the payload. A PHP script with a PNG signature
glued to the front came back as `image/png` at 1752113186 by 1885436268 pixels.

The first version of the avatar validator relied on `getimagesize()` plus a
dimension bound, and that combination did reject the file, but only because
those particular payload bytes happened to decode to absurd numbers. Four chosen
bytes would have produced a plausible size and walked through. So
`avatar_header_is_intact()` parses the header properly: for a PNG it verifies the
IHDR chunk's own CRC32, which no payload satisfies by accident.
`tests/avatar_test.php` carries a crafted polyglot with a plausible 64 by 64
size, which fails only on the CRC, so the test proves the structural check is
doing the work rather than the bounds.

Worth generalising: any upload check anywhere that trusts `getimagesize()` alone
is not a check. It is now rule 14 in `CLAUDE.md`.

**Still optional: re-encoding through GD.** Decoding the upload and writing a
fresh image is the strongest treatment, because it discards everything that is
not pixel data. `extension=gd` is commented out in `php.ini` on this machine, and
writing a branch that cannot be executed here, and that would start running the
moment somebody uncommented that line, is the worse trade. Enable GD first, then
add re-encoding with tests that actually run. The picture is already stored where
Apache will not serve it and streamed back with an explicit Content-Type, so this
is a strengthening, not a fix.

## After this

The AI-output validation rubric run, which is blocked on a real API key. See
`CLAUDE.md` "Still to do" item 1.
