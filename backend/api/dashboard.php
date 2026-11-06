<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_method('GET');
require_auth();

$period = get_period_value($_GET['period'] ?? null);
$pdo = db();

$stmt = $pdo->prepare(
    'SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN rec_status = "matched" THEN 1 ELSE 0 END) AS matched,
        SUM(CASE WHEN rec_status = "possible" THEN 1 ELSE 0 END) AS possible,
        SUM(CASE WHEN rec_status = "unmatched" THEN 1 ELSE 0 END) AS unmatched,
        SUM(CASE WHEN rec_status = "duplicate" THEN 1 ELSE 0 END) AS duplicate,
        SUM(CASE WHEN rec_status = "pending" THEN 1 ELSE 0 END) AS pending
     FROM records WHERE period = :period'
);
$stmt->execute([':period' => $period]);
$counts = $stmt->fetch();

$discrepancyQry = $pdo->prepare(
    'SELECT SUM(CASE WHEN status NOT IN ("resolved", "escalated") THEN 1 ELSE 0 END) AS needs_review,
            SUM(CASE WHEN status != "resolved" AND DATEDIFF(:as_of_date, DATE(flagged_at)) >= (SELECT CAST(svalue AS SIGNED) FROM settings WHERE skey = "overdue_days" LIMIT 1) THEN 1 ELSE 0 END) AS overdue
     FROM discrepancies WHERE period = :period'
);
$discrepancyQry->execute([':period' => $period, ':as_of_date' => app_today()]);
$discrepancy = $discrepancyQry->fetch();

$total = (int) ($counts['total'] ?? 0);
$matched = (int) ($counts['matched'] ?? 0);
$possible = (int) ($counts['possible'] ?? 0);
$unmatched = (int) ($counts['unmatched'] ?? 0);
$duplicate = (int) ($counts['duplicate'] ?? 0);
$pending = (int) ($counts['pending'] ?? 0);
$needsReview = (int) ($discrepancy['needs_review'] ?? 0);
$overdue = (int) ($discrepancy['overdue'] ?? 0);
$rate = $total > 0 ? round(($matched / $total) * 100, 2) : 0;

$activitiesStmt = $pdo->prepare(
    'SELECT action, reference, user_label, occurred_at, module
     FROM audit_log
     ORDER BY occurred_at DESC
     LIMIT 6'
);
$activitiesStmt->execute();
$activities = $activitiesStmt->fetchAll();

json_success([
    'stats' => [
        'total' => $total,
        'matched' => $matched,
        'possible' => $possible,
        'unmatched' => $unmatched,
        'duplicate' => $duplicate,
        'pending' => $pending,
        'needsReview' => $needsReview,
        'overdue' => $overdue,
        'rate' => $rate,
    ],
    'activities' => array_map(static function (array $row): array {
        return [
            'action' => $row['action'],
            'reference' => $row['reference'],
            'user_label' => $row['user_label'],
            'occurred_at' => $row['occurred_at'],
            'module' => $row['module'],
        ];
    }, $activities),
]);
