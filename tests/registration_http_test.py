"""Local HTTP smoke test. Creates and removes only its own account; sends no emails."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
BASE = "http://localhost/eduflex-ui/"
PHP = os.environ.get("PHP", r"C:\xampp\php\php.exe")
EMAIL = "auth-smoke-" + secrets.token_hex(12) + "@example.invalid"
PASSWORD = "Smoke-test-password-22"
checks = 0


def check(label, condition):
    global checks
    if not condition:
        raise AssertionError(label)
    checks += 1
    print("PASS " + label)


def database_state(clean=False):
    # Test email is generated above, not a user-supplied deletion target.
    script = r'''<?php
    require 'config/database.php';
    $email = getenv('EDUFLEX_SMOKE_EMAIL');
    if (!preg_match('/^auth-smoke-[a-f0-9]{24}@example\.invalid$/D', $email)) { exit(2); }
    $stmt = db()->prepare('SELECT user_id, email_verified_at FROM user WHERE email = ?');
    $stmt->execute([$email]); $user = $stmt->fetch();
    if (getenv('EDUFLEX_SMOKE_CLEAN') === '1' && $user) {
        db()->prepare('DELETE FROM user WHERE user_id = ? AND email = ?')->execute([$user['user_id'], $email]);
        $user = null;
    }
    $trust = []; $codes = 0;
    if ($user) {
        $stmt = db()->prepare('SELECT registration_pending, created_at, expires_at FROM trusted_browser WHERE user_id = ?');
        $stmt->execute([$user['user_id']]); $trust = $stmt->fetchAll();
        $stmt = db()->prepare('SELECT COUNT(*) FROM email_verification WHERE user_id = ?');
        $stmt->execute([$user['user_id']]); $codes = (int) $stmt->fetchColumn();
    }
    echo json_encode(['user' => $user ?: null, 'trust' => $trust, 'codes' => $codes,
        'accounts' => (int) db()->query('SELECT COUNT(*) FROM user')->fetchColumn()]);
    '''
    env = dict(os.environ, EDUFLEX_SMOKE_EMAIL=EMAIL, EDUFLEX_SMOKE_CLEAN="1" if clean else "0")
    result = subprocess.run([PHP], input=script, text=True, capture_output=True, cwd=ROOT, env=env, check=True)
    return json.loads(result.stdout)


def csrf(html):
    return re.search(r'name="_csrf" value="([a-f0-9]+)"', html).group(1)


jar = http.cookiejar.CookieJar()
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))


def request(route, data=None):
    payload = None if data is None else urllib.parse.urlencode(data).encode()
    with client.open(BASE + route, data=payload, timeout=20) as response:
        return response.geturl(), response.read().decode("utf-8")


before = database_state()
assert before["user"] is None
try:
    _, html = request("register.php")
    check("registration renders Confirm Password below Password",
          html.index('id="password"') < html.index('id="confirm_password"'))
    payload = dict(full_name="Temporary Auth Smoke", email=EMAIL, password=PASSWORD,
                   confirm_password="mismatch", agree="1", _csrf=csrf(html))
    _, html = request("register.php", payload)
    check("mismatching passwords are rejected without creating an account",
          "The two passwords do not match." in html and database_state()["user"] is None)
    payload.update(confirm_password=PASSWORD, _csrf=csrf(html))
    url, html = request("register.php", payload)
    check("registration redirects to login", url.endswith("/login.php") and "Account created" in html)
    state = database_state()
    check("new account is unverified and no code was sent", state["user"]["email_verified_at"] is None and state["codes"] == 0)
    check("registration creates one browser grant awaiting activation",
          len(state["trust"]) == 1 and state["trust"][0]["registration_pending"] == 1)
    check("browser receives an HttpOnly trust cookie scoped to EduFlex",
          any(c.name == "eduflex_browser" and c.path == "/eduflex-ui/" and c.has_nonstandard_attr("HttpOnly") for c in jar))
    url, html = request("login.php", dict(email=EMAIL, password=PASSWORD, _csrf=csrf(html), next=""))
    check("first login reaches dashboard without a code", url.endswith("/app/dashboard.php") and "Welcome back" in html)
    state = database_state()
    check("first login activates exactly seven days of trust",
          state["trust"][0]["registration_pending"] == 0
          and state["trust"][0]["expires_at"] - state["trust"][0]["created_at"] == 604800
          and state["codes"] == 0 and state["user"]["email_verified_at"] is None)
    expiry = state["trust"][0]["expires_at"]
    _, html = request("app/settings.php")
    check("unverified user can access Settings and see Verify Email",
          'action="actions/verify_email.php"' in html and ">Verify Email</button>" in html)
    url, html = request("app/actions/verify_email.php", dict(_csrf="invalid"))
    check("Verify Email action rejects invalid CSRF without sending email",
          url.endswith("/app/settings.php") and "Your session expired" in html and database_state()["codes"] == 0)
    request("auth/logout.php")
    _, html = request("login.php")
    url, _ = request("login.php", dict(email=EMAIL, password=PASSWORD, _csrf=csrf(html), next=""))
    check("subsequent trusted login does not renew trust or send a code",
          url.endswith("/app/dashboard.php") and database_state()["trust"][0]["expires_at"] == expiry
          and database_state()["codes"] == 0)
finally:
    after = database_state(clean=True)
    check("temporary account removed and existing account count preserved",
          after["user"] is None and after["accounts"] == before["accounts"])
print(f"\nPassed: {checks}   Failed: 0")
