<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_method('GET');
require_auth();

$period = get_period_value($_GET['period'] ?? null);
$pdo = db();
$asOfDate = app_today();
$overdueDays = max(1, (int) get_setting('overdue_days', 16));

$stmt = $pdo->prepare(
    'SELECT COUNT(*) AS total,
            SUM(CASE WHEN rec_status = "matched" THEN 1 ELSE 0 END) AS matched,
            SUM(CASE WHEN rec_status = "possible" THEN 1 ELSE 0 END) AS possible,
            SUM(CASE WHEN rec_status = "unmatched" THEN 1 ELSE 0 END) AS unmatched,
            SUM(CASE WHEN rec_status = "duplicate" THEN 1 ELSE 0 END) AS duplicate,
            SUM(CASE WHEN rec_status = "pending" THEN 1 ELSE 0 END) AS pending
     FROM records WHERE period = :period'
);
$stmt->execute([':period' => $period]);
$counts = $stmt->fetch();

$needsReviewStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM discrepancies WHERE period = :period AND status NOT IN ("resolved", "escalated")'
);
$needsReviewStmt->execute([':period' => $period]);

$overdueStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM discrepancies WHERE period = :period AND status != "resolved" AND DATEDIFF(:as_of_date, DATE(flagged_at)) >= :overdue_days'
);
$overdueStmt->execute([':period' => $period, ':as_of_date' => $asOfDate, ':overdue_days' => $overdueDays]);

$discrepancyStmt = $pdo->prepare(
    'SELECT d.*, COALESCE(u.full_name, "Unassigned") AS assigned_to,
            DATEDIFF(:as_of_date, DATE(d.flagged_at)) AS days_open
     FROM discrepancies d
     LEFT JOIN users u ON u.id = d.assigned_to
     WHERE d.period = :period
     ORDER BY d.flagged_at DESC, d.id DESC'
);
$discrepancyStmt->execute([':period' => $period, ':as_of_date' => $asOfDate]);

$total = (int) ($counts['total'] ?? 0);
$matched = (int) ($counts['matched'] ?? 0);
$rate = $total > 0 ? round(($matched / $total) * 100, 2) : 0;

json_success([
    'stats' => [
        'total' => $total,
        'matched' => $matched,
        'possible' => (int) ($counts['possible'] ?? 0),
        'unmatched' => (int) ($counts['unmatched'] ?? 0),
        'duplicate' => (int) ($counts['duplicate'] ?? 0),
        'pending' => (int) ($counts['pending'] ?? 0),
        'needsReview' => (int) $needsReviewStmt->fetchColumn(),
        'overdue' => (int) $overdueStmt->fetchColumn(),
        'rate' => $rate,
    ],
    'overdueDays' => $overdueDays,
    'toleranceDays' => max(0, (int) get_setting('date_tolerance_days', 2)),
    'discrepancies' => $discrepancyStmt->fetchAll(),
]);
