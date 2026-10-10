<?php
declare(strict_types=1);

function mail_config(): array
{
    // Explicit test injection, inaccessible to web requests.
    if (PHP_SAPI === 'cli' && isset($GLOBALS['eduflex_test_mail_config'])) {
        return $GLOBALS['eduflex_test_mail_config'];
    }
    $path = __DIR__ . '/../config/mail.php';
    return require is_file($path) ? $path : __DIR__ . '/../config/mail.example.php';
}

function email_verification_enabled(): bool
{
    return (bool) (mail_config()['enabled'] ?? false);
}

function mail_verification_code(string $email, string $code): bool
{
    if (PHP_SAPI === 'cli' && isset($GLOBALS['eduflex_test_mail_sender'])) {
        return (bool) ($GLOBALS['eduflex_test_mail_sender'])($email, $code);
    }
    return mail_send_text($email, 'Your EduFlex verification code',
        "Your EduFlex verification code is: {$code}\n\n"
        . "This code expires in 10 minutes and can be used only once.\n"
        . "Enter it in the EduFlex browser where you requested it.\n\n"
        . "If you did not request this code, ignore this email. Never share your code.");
}

function mail_send_text(string $email, string $subject, string $body): bool
{
    $config = mail_config();
    if (empty($config['enabled']) || empty($config['username']) || empty($config['password'])
        || !filter_var($config['from_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
        error_log('EduFlex verification email: SMTP configuration is incomplete.');
        return false;
    }
    try {
        require_once __DIR__ . '/vendor/phpmailer/Exception.php';
        require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
        require_once __DIR__ . '/vendor/phpmailer/SMTP.php';
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = (string) $config['host'];
        $mail->Port = (int) $config['port'];
        $mail->SMTPAuth = true;
        $mail->Username = (string) $config['username'];
        $mail->Password = (string) $config['password'];
        $mail->SMTPSecure = $mail->Port === 465 ? 'ssl' : 'tls';
        $mail->Timeout = 15;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom((string) $config['from_email'], (string) $config['from_name']);
        $mail->addAddress($email);
        $mail->Subject = $subject;
        $mail->Body = $body;
        return $mail->send();
    } catch (Throwable $e) {
        // Never log the SMTP exchange, recipient, code, or credentials.
        error_log('EduFlex verification email delivery failed; check SMTP settings and connectivity.');
        return false;
    }
}
