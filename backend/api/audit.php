<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_method('GET');
require_auth();

$period = get_period_value($_GET['period'] ?? null);
$pdo = db();
$stmt = $pdo->prepare(
    'SELECT * FROM audit_log
     WHERE DATE_FORMAT(occurred_at, "%Y-%m") = :period
     ORDER BY occurred_at DESC
     LIMIT 200'
);
$stmt->execute([':period' => $period]);

json_success($stmt->fetchAll());
