<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/support_matching.php';

require_method('GET');
require_auth();

$period = get_period_value($_GET['period'] ?? null);
$pdo = db();
ensure_support_matches_table($pdo);
$stmt = $pdo->prepare(
    'SELECT sm.id, sm.period, sm.ledger_id, sm.support_record_id,
            sm.amount_ok, sm.reference_ok, sm.date_ok, sm.date_diff,
            sm.result, sm.confirmed, sm.note,
            l.ref_no AS ledger_ref, l.support_ref AS expected_support_ref,
            l.amount AS ledger_amount, l.txn_date AS ledger_date,
            s.ref_no AS support_ref, s.source AS support_source,
            s.amount AS support_amount, s.txn_date AS support_date
     FROM support_matches sm
     INNER JOIN records l ON l.id = sm.ledger_id
     LEFT JOIN records s ON s.id = sm.support_record_id
     WHERE sm.period = :period
     ORDER BY sm.id DESC'
);
$stmt->execute([':period' => $period]);

json_success($stmt->fetchAll());
