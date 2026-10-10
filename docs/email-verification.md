# Email verification and seven-day browser trust

The feature is implemented but disabled until SMTP is configured. Existing
accounts and development data are preserved. Once enabled, every account
needs a code when signing in from an untrusted browser, including existing
accounts on their first sign-in. Any valid email address can receive a code;
the sender uses Gmail, but recipients are not restricted to Gmail.

## Enable on XAMPP

1. Apply the additive migrations: `C:\xampp\php\php.exe tools\migrate.php --apply`.
   Never import `01_schema.sql` over your working database.
2. Sign in to the sender's Google account (`eduflex54@gmail.com` in this install).
   Enable 2-Step Verification and create an App Password named EduFlex.
   Google instructions: https://support.google.com/accounts/answer/185833
   App Passwords may be unavailable for some managed or protected accounts.
3. Open `config/mail.php` locally. It is ignored by Git and Apache denies access
   to the configuration folder. If absent, copy `config/mail.example.php` first.
   Enter the App Password in `password` (without spaces); never use the ordinary
   Gmail password. Set `username` and `from_email` to the sender Gmail address.
4. Change `enabled` to `true`. Keep host `smtp.gmail.com` and port `587` for STARTTLS.
5. Run `C:\xampp\php\php.exe tools\mail_check.php --send-test` and check the
   sender mailbox, including spam. This sends a plain delivery test, not a code.
6. Open `http://localhost/eduflex-ui/login.php` and sign in. Enter the six-digit
   code from the email, optionally checking “Trust this browser for 7 days”.

Credentials never enter JavaScript, error messages, exports, or tracked files.
PHPMailer 7.1.1 is bundled locally with its license; Composer is unnecessary.
SMTP certificate verification is left enabled. If delivery fails because of a
certificate error, configure a valid CA bundle in XAMPP's PHP configuration;
do not disable certificate verification.

## Behavior

- Registration requires matching Password and Confirm Password fields, creates
  an unverified account, sends no email, and redirects to login.
- The registering browser receives a one-time initial trust grant. Its first
  correct-password login activates seven days of trust, allowing access without
  a code. This pending grant must be used within seven days of registration;
  losing the cookie or using another browser requires an email code instead.
  Ordinary sign-in never renews trust. Registration does not verify the email.
- Profile Settings shows “Verify Email” until email ownership is confirmed.
  This POST action sends a code while preserving the signed-in session. Completing
  it marks the email verified and returns to Settings without extending browser
  trust. Users may cancel and continue during their initial seven-day grace.
- After initial trust expires, unverified users must complete emailed-code
  verification to sign in. Active unverified sessions also stop at the grace
  deadline. Users with verified emails still need a code on a new browser or
  when browser trust expires. Successful sign-in codes confirm email ownership.
- Password verification precedes sign-in code delivery. A pending challenge is
  not an authenticated session and cannot access application pages.
- Codes expire after 10 minutes, are single-use and session-bound, and are
  password-hashed in the database. One current code exists per account: requesting
  a code elsewhere replaces the previous one.
- Five wrong guesses lock verification for 15 minutes. Resending cannot reset
  guesses; request a new code after the lockout. Pending verification expires
  after 30 minutes and then requires starting sign-in again.
- Resends have a 60-second cooldown and a maximum of five emails per account
  per hour. Undelivered codes cannot sign in; failed sends count against this
  limit to avoid repeated SMTP requests.
- The optional trust cookie is HttpOnly, SameSite=Lax and Secure over HTTPS.
  Only its SHA-256 hash is stored. Trust expires seven days from the first
  registration-browser login or a subsequent code verification with trust selected,
  without renewal on ordinary sign-in. A password is always required.
- Signing out ends the session but retains browser trust. Incognito, cleared
  cookies, or another browser requires verification again. A trust token is a
  security credential: anyone holding a copy plus the password can use it.
- Password changes revoke all browser trust and outstanding codes. Email changes
  revoke them too, clear verified email ownership, and sign out the current
  session so the new address must be verified. This is not a password-reset feature.
- When verification is enabled, sessions are checked against current account
  credentials. Email changes and password changes invalidate older sessions;
  the browser performing a password change stays signed in. Enabling the feature
  requires existing sessions without an initial trust grant to sign in and
  confirm their email too.
- Disabling `enabled` restores password-only login. It is a configuration choice,
  not an automatic fallback: once enabled, SMTP failures never bypass verification.

## Validation

`C:\xampp\php\php.exe tests\verification_test.php` exercises real authentication
against SQLite memory, capturing emails through a CLI-only test hook. It touches
no live accounts and sends no real emails. Existing suites test the original
behavior with verification disabled. Real Gmail delivery must be checked after
the App Password is entered locally.

`python tests/registration_http_test.py` checks registration, cookie issuance,
first login, Settings, CSRF rejection, and repeated trusted login over localhost.
It creates one unique temporary account, deletes only that account afterwards,
preserves existing users, and sends no emails. It expects verification to be
enabled and the additive migration applied.
