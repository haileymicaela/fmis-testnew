<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_method('GET');
require_auth();

$period = get_period_value($_GET['period'] ?? null);
$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM records WHERE period = :period ORDER BY txn_date DESC, id DESC');
$stmt->execute([':period' => $period]);

json_success($stmt->fetchAll());
