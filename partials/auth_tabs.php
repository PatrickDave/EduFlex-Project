<?php
/**
 * The User / Admin switch at the top of the two sign-in cards.
 *
 * Two separate pages, not one page with a toggle. Each tab is a plain link to
 * the other page, so the control works with no JavaScript and the address bar
 * always says which door you are at. The pages themselves stay what they were:
 * login.php admits learners and refuses team accounts, admin_login.php admits
 * team accounts and refuses learners.
 *
 * Expects $current in scope: 'user' or 'admin'.
 *
 * One file because the two cards must not drift apart. A switch that is in a
 * different place, or named differently, on each page reads as two unrelated
 * screens rather than one choice.
 */

declare(strict_types=1);

$current = ($current ?? 'user') === 'admin' ? 'admin' : 'user';

$tabs = [
    'user'  => ['label' => 'User',  'href' => 'login.php'],
    'admin' => ['label' => 'Admin', 'href' => 'admin_login.php'],
];
?>
<div class="ef-auth-tabs" role="tablist" aria-label="Choose who is signing in">
  <?php foreach ($tabs as $key => $tab): ?>
    <?php $on = $key === $current; ?>
    <a class="ef-auth-tab<?= $on ? ' is-on' : '' ?>"
       href="<?= e($tab['href']) ?>"
       role="tab"
       <?= $on ? 'aria-selected="true" aria-current="page"' : 'aria-selected="false"' ?>>
      <?= e($tab['label']) ?>
    </a>
  <?php endforeach; ?>
</div>
