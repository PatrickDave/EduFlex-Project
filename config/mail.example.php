<?php
declare(strict_types=1);

// Copy to mail.php (ignored by Git). Enable only after filling the credentials.
// Use a Gmail App Password, never your ordinary Google password.
return [
    'enabled' => false,
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'username' => '',
    'password' => '',
    'from_email' => '', // Usually the same address as username.
    'from_name' => 'EduFlex',
];
