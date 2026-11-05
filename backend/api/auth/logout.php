<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

require_method('POST');
check_csrf_if_present();

if (!empty($_SESSION['user_id'])) {
    log_audit_event('User logout', 'Authentication', null, 'success', 'Logged out');
}

session_unset();
session_destroy();

json_success(['ok' => true]);
