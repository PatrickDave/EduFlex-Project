<?php
/**
 * EduFlex — sign out and return to the landing page.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

auth_logout();
redirect('../index.php');
