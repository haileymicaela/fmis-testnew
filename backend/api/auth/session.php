<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

require_method('GET');

json_success([
    'user' => $_SESSION['user_name'] ?? null,
]);
