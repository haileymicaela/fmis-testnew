<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_name('cspc_accounting_session');
    session_set_cookie_params([
        'lifetime' => 3600,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/validation.php';

get_csrf_token();
