<?php
/**
 * EduFlex — footer for the public pages.
 *
 * Usage, from a page at the project root:
 *
 *     <?php include __DIR__ . '/partials/public_footer.php'; ?>
 *
 * This existed as three identical copies in index.php, login.php and
 * register.php, which is how "Privacy Policy" stayed pointed at href="#" in
 * all three for as long as it did: fixing one copy fixed nothing. Adding the
 * two legal pages made it four, so the markup moved here.
 *
 * Every public page sits at the project root, so the paths are relative to
 * that and need no prefix.
 */
?>
<footer class="ef-page-footer">
  <span>&copy; 2026 EduFlex. University of Cebu, College of Computer Studies.</span>
  <span>
    <a href="privacy.php">Privacy Policy</a>
    <a href="terms.php">Terms of Service</a>
    <?php
    /* Support lives inside the app, so this bounces through the login screen
       for a visitor who is not signed in. That is the correct destination
       rather than a second public form: a support request is stored against an
       account, and there is no way to record one without an account to attach
       it to. */
    ?>
    <a href="app/support.php">Contact Support</a>
  </span>
</footer>
