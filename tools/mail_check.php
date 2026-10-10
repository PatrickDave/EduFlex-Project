<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/mail.php';
$config = mail_config();
$ready = !empty($config['username']) && !empty($config['password'])
    && filter_var($config['from_email'] ?? '', FILTER_VALIDATE_EMAIL);
echo 'Email verification: ' . (email_verification_enabled() ? 'enabled' : 'disabled') . PHP_EOL;
echo 'SMTP credentials: ' . ($ready ? 'filled in (not displayed)' : 'incomplete') . PHP_EOL;
if (!in_array('--send-test', $argv, true)) {
    echo "After configuration, run this command with --send-test to send a test to the sender mailbox.\n";
    exit($ready && email_verification_enabled() ? 0 : 1);
}
if (!$ready || !email_verification_enabled()) {
    fwrite(STDERR, "Fill config/mail.php and enable verification first.\n"); exit(1);
}
$sent = mail_send_text((string) $config['from_email'], 'EduFlex SMTP delivery test',
    "EduFlex can send security emails from this mailbox.\nThis is a configuration test, not a sign-in code.");
echo $sent ? "Test accepted by SMTP. Check the sender inbox and spam folder.\n" : "Delivery failed. Check the SMTP configuration and server error log.\n";
exit($sent ? 0 : 1);
