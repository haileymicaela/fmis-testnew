<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/support_matching.php';

require_method('POST');
require_auth();
check_csrf_if_present();

$payload = read_json_input();
$id = (int) ($payload['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    json_error('A valid supporting match identifier is required.', 422);
}

$pdo = db();
ensure_support_matches_table($pdo);
$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare(
        'SELECT sm.*, l.source AS ledger_source, l.period AS ledger_period,
                l.support_ref AS ledger_support_ref, l.amount AS ledger_amount, l.txn_date AS ledger_date,
                s.source AS support_source, s.period AS support_period,
                s.ref_no AS support_ref, s.amount AS support_amount, s.txn_date AS support_date
         FROM support_matches sm
         INNER JOIN records l ON l.id = sm.ledger_id
         LEFT JOIN records s ON s.id = sm.support_record_id
         WHERE sm.id = :id
         FOR UPDATE'
    );
    $stmt->execute([':id' => $id]);
    $match = $stmt->fetch();
    if (!$match) {
        json_error('Supporting match not found.', 404);
    }

    $toleranceDays = (int) get_setting('date_tolerance_days', 2);
    $validSources = ['sias', 'voucher'];
    $valid = $match['support_record_id'] !== null
        && $match['ledger_source'] === 'ledger'
        && in_array($match['support_source'], $validSources, true)
        && $match['ledger_period'] === $match['period']
        && $match['support_period'] === $match['period']
        && trim((string) $match['ledger_support_ref']) !== ''
        && $match['ledger_support_ref'] === $match['support_ref']
        && $match['ledger_amount'] !== null
        && $match['support_amount'] !== null
        && (float) $match['ledger_amount'] === (float) $match['support_amount']
        && abs((int) (new DateTimeImmutable((string) $match['ledger_date']))
            ->diff(new DateTimeImmutable((string) $match['support_date']))->days) <= $toleranceDays
        && $match['result'] === 'matched';

    if (!$valid) {
        json_error('Only exact one-to-one ledger-to-SIAS or ledger-to-voucher matches within the date tolerance can be confirmed.', 422);
    }

    if ((int) $match['confirmed'] === 1) {
        $pdo->commit();
        json_success($match);
    }

    $otherLedger = $pdo->prepare(
        'SELECT id FROM records
         WHERE period = :period AND source = "ledger" AND support_ref = :support_ref
           AND id <> :ledger_id
         LIMIT 1 FOR UPDATE'
    );
    $otherLedger->execute([
        ':period' => $match['period'],
        ':support_ref' => $match['support_ref'],
        ':ledger_id' => (int) $match['ledger_id'],
    ]);
    if ($otherLedger->fetch()) {
        json_error('This supporting reference is used by more than one ledger entry and cannot be confirmed as a one-to-one match.', 409);
    }

    $conflict = $pdo->prepare(
        'SELECT id FROM support_matches
         WHERE support_record_id = :support_record_id AND confirmed = 1 AND id <> :id
         LIMIT 1 FOR UPDATE'
    );
    $conflict->execute([
        ':support_record_id' => (int) $match['support_record_id'],
        ':id' => $id,
    ]);
    if ($conflict->fetch()) {
        json_error('This SIAS or voucher record is already confirmed against another ledger entry.', 409);
    }

    $pdo->prepare(
        'UPDATE support_matches
         SET confirmed = 1, confirmed_by = :user_id, confirmed_at = NOW(), result = "matched"
         WHERE id = :id'
    )->execute([
        ':user_id' => $_SESSION['user_id'] ?? null,
        ':id' => $id,
    ]);
    $pdo->prepare('UPDATE records SET rec_status = "matched" WHERE id = :support_record_id')
        ->execute([':support_record_id' => (int) $match['support_record_id']]);

    $pdo->prepare(
        'UPDATE discrepancies
         SET status = "resolved", resolved_by = :user_id, resolved_at = NOW(), resolution_note = :note
         WHERE period = :period AND issue_type IN ("no_match", "duplicate", "date_gap")
           AND ((record_id = :ledger_a AND counter_record_id = :support_a)
             OR (record_id = :support_b AND counter_record_id = :ledger_b))'
    )->execute([
        ':user_id' => $_SESSION['user_id'] ?? null,
        ':note' => 'Supporting record match confirmed',
        ':period' => $match['period'],
        ':ledger_a' => (int) $match['ledger_id'],
        ':support_a' => (int) $match['support_record_id'],
        ':support_b' => (int) $match['support_record_id'],
        ':ledger_b' => (int) $match['ledger_id'],
    ]);

    log_audit_event('Supporting record match confirmed', 'Matching', (string) $match['id'], 'success', 'Ledger linked to ' . $match['support_source'] . ' reference ' . $match['support_ref']);
    $result = $pdo->prepare('SELECT * FROM support_matches WHERE id = :id LIMIT 1');
    $result->execute([':id' => $id]);
    $confirmed = $result->fetch();
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

json_success($confirmed);
