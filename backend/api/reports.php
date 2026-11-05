<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_method('GET');
require_auth();

$period = get_period_value($_GET['period'] ?? null);
$pdo = db();
$stmt = $pdo->prepare(
    'SELECT r.id, r.report_code, r.report_type, r.period, r.generated_at, r.format, COALESCE(u.full_name, "System") AS generated_by
     FROM reports r
     LEFT JOIN users u ON u.id = r.generated_by
     WHERE r.period = :period
     ORDER BY r.generated_at DESC'
);
$stmt->execute([':period' => $period]);

json_success($stmt->fetchAll());
