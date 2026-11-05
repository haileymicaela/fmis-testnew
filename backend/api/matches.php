<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';

require_method('GET');
require_auth();

$period = get_period_value($_GET['period'] ?? null);
$pdo = db();
$stmt = $pdo->prepare(
    'SELECT m.id, m.period, m.bank_id, m.ledger_id,
            m.amount, m.a_ok, m.r_ok, m.d_ok, m.date_diff, m.result, m.confirmed,
            b.ref_no AS bank_ref,
            l.ref_no AS ledger_ref,
            b.cross_ref AS ref_no
     FROM matches m
     LEFT JOIN records b ON b.id = m.bank_id
     LEFT JOIN records l ON l.id = m.ledger_id
     WHERE m.period = :period
     ORDER BY m.id DESC'
);
$stmt->execute([':period' => $period]);

json_success($stmt->fetchAll());
