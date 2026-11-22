<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/auth.php';

require_role(ROLE_HEAD_ACCOUNTANT);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store');
readfile(__DIR__ . '/../frontend/audit-trail.html');
