<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staging.php';

require_method('GET');
require_auth();

$period = get_period_value($_GET['period'] ?? null);
$pdo = db();
ensure_staging_tables($pdo);

$stmt = $pdo->prepare(
    'SELECT si.id, si.source, si.file_name, si.staged_at, si.staged_count,
            si.duplicate_count, si.rejected_count, COUNT(sr.id) AS ready_count
     FROM staged_imports si
     LEFT JOIN staged_records sr ON sr.staged_import_id = si.id
     WHERE si.period = :period AND si.status = "staged"
     GROUP BY si.id
     ORDER BY si.staged_at, si.id'
);
$stmt->execute([':period' => $period]);
$imports = $stmt->fetchAll();
$summary = ['files' => count($imports), 'records' => 0, 'duplicates' => 0, 'rejected' => 0];
foreach ($imports as $import) {
    $summary['records'] += (int) $import['ready_count'];
    $summary['duplicates'] += (int) $import['duplicate_count'];
    $summary['rejected'] += (int) $import['rejected_count'];
}

json_success(['period' => $period, ...$summary, 'imports' => $imports]);
