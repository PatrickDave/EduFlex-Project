<?php
/**
 * EduFlex — Manage User Accounts
 *
 * GENERATED FILE. Edit the template in build_pages.py, not this file.
 * Every page inside /app is gated: auth_require_login() sends anyone who is
 * not signed in back to the login screen before a single byte is output.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../includes/avatar.php';
admin_require();
auth_require_login();

$user   = auth_user();
$active = 'admin_users';
$title  = 'Manage User Accounts';

/* The project team's account has no learning of its own.
 *
 * eduflex54@gmail.com is a shared address, not a participant: it has uploaded
 * nothing, answered nothing and has no mastery to look at, so every learner
 * screen would show it an empty state that means nothing. Patrick asked on
 * 2 November 2026 that it stay out of the learner area entirely.
 *
 * There used to be an exception for Settings, because that was the only place
 * the team could change its own password. app/admin_settings.php does that now,
 * so the exception is gone and the separation is complete: no learner page at
 * all, not even one.
 *
 * This runs before any output, so redirect() can still fire. The reverse
 * direction, a learner reaching an admin page, is held by admin_require() in
 * the requires slot above. */
if ($user !== null
    && admin_is_admin($user)
    && !str_starts_with($active, 'admin')) {
    redirect('admin.php');
}

/* The "Login Successful" welcome, once, on the first page after signing in.
   includes/auth.php sets the marker in auth_complete_login(), which only a
   successful sign-in reaches. Read and cleared here so a refresh does not
   replay it. Learners and the team both see it (the team since 24 November
   2026, at Patrick's request), each with its own message: see
   partials/login_welcome.php. A learner who tries the admin door is signed
   out by admin_login.php, which destroys the session and the marker with it,
   so this never plays for a refused sign-in on either door. */
$loginWelcome = false;
$loginWelcomeTeam = false;
if (isset($_SESSION['_login_welcome'])) {
    unset($_SESSION['_login_welcome']);
    $loginWelcome = $user !== null;
    $loginWelcomeTeam = $loginWelcome && admin_is_admin($user);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> — EduFlex</title>
<link href="../assets/vendor/bootstrap-5.3.3.min.css" rel="stylesheet">
<link href="<?= asset('../assets/css/eduflex.css') ?>" rel="stylesheet">
</head>
<body<?= $loginWelcome ? ' class="ef-entering"' : '' ?>>

<?php if ($loginWelcome): include __DIR__ . '/../partials/login_welcome.php'; endif; ?>

<div class="ef-shell">

<?php include __DIR__ . '/../partials/sidebar.php'; ?>

    <div class="ef-main">

<?php include __DIR__ . '/../partials/topbar.php'; ?>

<?php
/* Every filter is checked against a fixed list here, and again inside
   admin_accounts(), so nothing a visitor types reaches the SQL or an href. */
$q        = is_string($_GET['q'] ?? null) ? trim((string) $_GET['q']) : '';
$q        = mb_substr($q, 0, 100);
$pick     = static fn(string $key, array $allowed): string =>
    is_string($_GET[$key] ?? null) && in_array($_GET[$key], $allowed, true) ? (string) $_GET[$key] : '';
$status   = $pick('status', ['active', 'inactive']);
$verified = $pick('verified', ['yes', 'no']);
$role     = $pick('role', ['learner', 'team']);
$sort     = $pick('sort', array_keys(ADMIN_ACCOUNT_SORTS)) ?: 'newest';

$accounts = admin_accounts($q, $status, $verified, $role, $sort);
$summary  = admin_account_summary();
$ok       = flash_get('users_ok');
$err      = flash_get('users_error');
$filtered = $q !== '' || $status !== '' || $verified !== '' || $role !== '';

$link = static function (array $over) use ($q, $status, $verified, $role, $sort): string {
    $params = array_filter(array_merge(
        ['q' => $q, 'status' => $status, 'verified' => $verified, 'role' => $role,
         'sort' => $sort === 'newest' ? '' : $sort],
        $over
    ), static fn($v) => (string) $v !== '');
    return $params === [] ? 'admin_users.php' : 'admin_users.php?' . http_build_query($params);
};

$tiles = [
    ['Total accounts', $summary['total'],      $link(['status' => '', 'verified' => '', 'role' => '', 'q' => '']), !$filtered],
    ['Active',         $summary['active'],     $link(['status' => 'active']),   $status === 'active'],
    ['Deactivated',    $summary['inactive'],   $link(['status' => 'inactive']), $status === 'inactive'],
    ['Email not verified', $summary['unverified'], $link(['verified' => 'no']), $verified === 'no'],
    ['Team',           $summary['team'],       $link(['role' => 'team']),       $role === 'team'],
];
?>
      <div class="ef-content">

        <div class="ef-page-head">
          <div>
            <h2>Manage user accounts</h2>
            <p>Everyone registered on EduFlex. Open an account to view, edit, deactivate or delete it.</p>
          </div>
        </div>

        <?php if ($ok): ?>
          <div class="ef-alert ef-alert-ok"><?= e((string) $ok) ?></div>
        <?php endif; ?>
        <?php if ($err): ?>
          <div class="ef-alert ef-alert-error"><?= e((string) $err) ?></div>
        <?php endif; ?>

        <div class="ef-admin-counts">
          <?php foreach ($tiles as [$label, $n, $href, $on]): ?>
            <a class="ef-admin-count ef-admin-count-link<?= $on ? ' is-on' : '' ?>" href="<?= e($href) ?>">
              <div class="ef-admin-count-n"><?= (int) $n ?></div>
              <div class="ef-admin-count-l"><?= e($label) ?></div>
            </a>
          <?php endforeach; ?>
        </div>

        <form class="ef-card ef-card-lg ef-account-filters" method="get" action="admin_users.php" role="search">
          <div class="ef-field ef-account-search">
            <label class="ef-label" for="q">Search</label>
            <input class="ef-input" type="search" id="q" name="q" maxlength="100"
                   value="<?= e($q) ?>" placeholder="Name, email or user ID" autocomplete="off">
          </div>
          <div class="ef-field">
            <label class="ef-label" for="status">Status</label>
            <select class="ef-input" id="status" name="status">
              <option value="">Any</option>
              <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option>
              <option value="inactive"<?= $status === 'inactive' ? ' selected' : '' ?>>Deactivated</option>
            </select>
          </div>
          <div class="ef-field">
            <label class="ef-label" for="verified">Email</label>
            <select class="ef-input" id="verified" name="verified">
              <option value="">Any</option>
              <option value="yes"<?= $verified === 'yes' ? ' selected' : '' ?>>Verified</option>
              <option value="no"<?= $verified === 'no' ? ' selected' : '' ?>>Not verified</option>
            </select>
          </div>
          <?php /* The Role dropdown was removed on 19 November 2026 at Patrick's
                   request. The Team tile above still filters by role, so it
                   is carried through here when set. */ ?>
          <?php if ($role !== ''): ?>
            <input type="hidden" name="role" value="<?= e($role) ?>">
          <?php endif; ?>
          <div class="ef-field">
            <label class="ef-label" for="sort">Sort</label>
            <select class="ef-input" id="sort" name="sort">
              <?php foreach (ADMIN_ACCOUNT_SORTS as $key => [$label, $unused]): ?>
                <option value="<?= e($key) ?>"<?= $sort === $key ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="ef-account-filter-actions">
            <button class="ef-btn ef-btn-primary" type="submit">Apply</button>
            <?php if ($filtered || $sort !== 'newest'): ?>
              <a class="ef-btn ef-btn-ghost" href="admin_users.php">Clear</a>
            <?php endif; ?>
          </div>
        </form>

        <section class="ef-card ef-card-lg">
          <div class="ef-card-head">
            <div class="ef-card-title">
              <?= $filtered ? 'Matching accounts' : 'All accounts' ?>
            </div>
            <span class="ef-muted" style="font-size:11.5px;">
              <?= count($accounts) ?> of <?= (int) $summary['total'] ?>
            </span>
          </div>

          <?php if ($accounts === []): ?>
            <div class="ef-empty-state">
              <div class="ef-empty-ico"></div>
              <h5><?= $filtered ? 'No account matches' : 'No accounts yet' ?></h5>
              <p>
                <?= $filtered
                      ? 'Try a shorter search, or clear the filters.'
                      : 'Accounts appear here as participants register.' ?>
              </p>
            </div>
          <?php else: ?>
            <div class="ef-account-table-wrap">
              <table class="ef-table ef-account-table">
                <thead>
                  <tr>
                    <th>User</th>
                    <th>Programme</th>
                    <th>Status</th>
                    <th>Activity</th>
                    <th>Joined</th>
                    <th><span class="visually-hidden">Actions</span></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($accounts as $a): ?>
                    <?php
                      $aid      = (int) $a['user_id'];
                      $isTeam   = admin_is_admin($a);
                      $isActive = (string) $a['account_status'] === 'active';
                      $href     = 'admin_user.php?id=' . $aid;
                    ?>
                    <tr<?= $isActive ? '' : ' class="is-inactive"' ?>>
                      <td data-label="User">
                        <a class="ef-account-who" href="<?= e($href) ?>">
                          <span class="ef-avatar ef-avatar-initials ef-account-avatar" aria-hidden="true"><?= e(avatar_initials((string) $a['full_name'])) ?></span>
                          <span class="ef-account-who-text">
                            <span class="ef-account-name"><?= e((string) $a['full_name']) ?></span>
                            <span class="ef-account-email"><?= e((string) $a['email']) ?></span>
                          </span>
                        </a>
                      </td>
                      <td data-label="Programme">
                        <?php if ($isTeam): ?>
                          <span class="ef-second">Project team</span>
                        <?php else: ?>
                          <?= e((string) $a['program']) ?>
                          <span class="ef-second">&middot; Year <?= (int) $a['year_level'] ?></span>
                        <?php endif; ?>
                      </td>
                      <td data-label="Status">
                        <div class="ef-account-pills">
                          <span class="ef-band ef-band-<?= e(admin_account_status_band((string) $a['account_status'])) ?>">
                            <?= e(admin_account_status_label((string) $a['account_status'])) ?>
                          </span>
                          <?php if ($isTeam): ?>
                            <span class="ef-band ef-band-none">Team</span>
                          <?php endif; ?>
                          <?php if (($a['email_verified_at'] ?? null) === null): ?>
                            <span class="ef-band ef-band-developing">Unverified</span>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td data-label="Activity" class="ef-account-activity">
                        <?= (int) $a['materials'] ?> material<?= (int) $a['materials'] === 1 ? '' : 's' ?>
                        &middot; <?= (int) $a['attempts'] ?> attempt<?= (int) $a['attempts'] === 1 ? '' : 's' ?>
                        &middot; <?= (int) $a['requests'] ?> request<?= (int) $a['requests'] === 1 ? '' : 's' ?>
                      </td>
                      <td data-label="Joined" class="ef-account-joined">
                        <?= e(date('j M Y', strtotime((string) $a['created_at']) ?: time())) ?>
                      </td>
                      <td class="ef-account-actions">
                        <a class="ef-btn ef-btn-ghost ef-btn-sm" href="<?= e($href) ?>">View</a>
                        <?php if (!$isTeam): ?>
                          <form method="post" action="actions/admin_user_status.php" style="margin:0;"
                                data-confirm="<?= e($isActive
                                    ? 'Deactivate ' . $a['email'] . '? They will be signed out and unable to sign in until you activate the account again. Nothing is deleted.'
                                    : 'Activate ' . $a['email'] . '? They will be able to sign in again.') ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="user_id" value="<?= $aid ?>">
                            <input type="hidden" name="status" value="<?= $isActive ? 'inactive' : 'active' ?>">
                            <input type="hidden" name="from" value="list">
                            <button class="ef-btn ef-btn-sm <?= $isActive ? 'ef-btn-ghost ef-btn-warn' : 'ef-btn-primary' ?>" type="submit">
                              <?= $isActive ? 'Deactivate' : 'Activate' ?>
                            </button>
                          </form>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <p class="ef-note" style="margin-top:16px;">
            Team accounts are listed but cannot be edited, deactivated or deleted here.
            Team access is granted and revoked only with tools/make_admin.php.
          </p>
        </section>

      </div>


    </div>
</div>

<script src="../assets/vendor/jquery-3.7.1.min.js"></script>
<script src="<?= asset('../assets/js/eduflex.js') ?>"></script>
</body>
</html>
