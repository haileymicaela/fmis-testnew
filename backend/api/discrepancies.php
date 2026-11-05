<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_method('GET');
require_auth();

$period = get_period_value($_GET['period'] ?? null);
$pdo = db();
$asOfDate = app_today();
$stmt = $pdo->prepare(
    'SELECT d.*, COALESCE(u.full_name, "Unassigned") AS assigned_to,
            DATEDIFF(:as_of_date, DATE(d.flagged_at)) AS days_open
     FROM discrepancies d
     LEFT JOIN users u ON u.id = d.assigned_to
     WHERE d.period = :period
     ORDER BY d.flagged_at DESC, d.id DESC'
);
$stmt->execute([':period' => $period, ':as_of_date' => $asOfDate]);

json_success($stmt->fetchAll());
