<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

require_method('GET');

$user = current_user();
header('Cache-Control: no-store');
json_success([
    'user' => $user['full_name'] ?? null,
    'profile' => $user,
    'csrf_token' => ($user['role_label'] ?? null) === ROLE_HEAD_ACCOUNTANT ? get_csrf_token() : null,
]);
