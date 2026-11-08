<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

require_method('POST');
require_auth();
check_csrf_if_present();

$payload = read_json_input();
$id = (int) ($payload['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    json_error('A valid match identifier is required.', 422);
}

$pdo = db();
$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare(
        'SELECT m.*, b.source AS bank_source, b.period AS bank_period, b.amount AS bank_amount,
                b.cross_ref AS bank_cross_ref, b.txn_date AS bank_date,
                l.source AS ledger_source, l.period AS ledger_period, l.amount AS ledger_amount,
                l.cross_ref AS ledger_cross_ref, l.txn_date AS ledger_date
         FROM matches m
         LEFT JOIN records b ON b.id = m.bank_id
         LEFT JOIN records l ON l.id = m.ledger_id
         WHERE m.id = :id
         FOR UPDATE'
    );
    $stmt->execute([':id' => $id]);
    $match = $stmt->fetch();
    if (!$match) {
        json_error('Match not found.', 404);
    }

    $toleranceDays = (int) get_setting('date_tolerance_days', 2);
    $valid = $match['bank_id'] !== null
        && $match['ledger_id'] !== null
        && $match['bank_source'] === 'bank'
        && $match['ledger_source'] === 'ledger'
        && $match['bank_period'] === $match['period']
        && $match['ledger_period'] === $match['period']
        && $match['bank_amount'] !== null
        && $match['ledger_amount'] !== null
        && (float) $match['bank_amount'] === (float) $match['ledger_amount']
        && trim((string) $match['bank_cross_ref']) !== ''
        && $match['bank_cross_ref'] === $match['ledger_cross_ref']
        && abs((int) (new DateTimeImmutable((string) $match['bank_date']))
            ->diff(new DateTimeImmutable((string) $match['ledger_date']))->days) <= $toleranceDays;

    if (!$valid) {
        json_error('Only exact bank-to-ledger matches within the date tolerance can be confirmed.', 422);
    }

    if ((int) $match['confirmed'] === 1) {
        $pdo->commit();
        json_success($match);
    }

    $conflicts = $pdo->prepare(
        'SELECT id FROM matches
         WHERE confirmed = 1 AND id <> :id
           AND (bank_id IN (:bank_id, :ledger_id) OR ledger_id IN (:other_bank_id, :other_ledger_id))
         LIMIT 1
         FOR UPDATE'
    );
    $conflicts->execute([
        ':id' => $id,
        ':bank_id' => (int) $match['bank_id'],
        ':ledger_id' => (int) $match['ledger_id'],
        ':other_bank_id' => (int) $match['bank_id'],
        ':other_ledger_id' => (int) $match['ledger_id'],
    ]);
    if ($conflicts->fetch()) {
        json_error('A conflicting confirmed match already uses one of these records.', 409);
    }

    $pdo->prepare(
        'UPDATE matches
         SET confirmed = 1, confirmed_by = :user_id, confirmed_at = NOW(), result = "matched"
         WHERE id = :id'
    )->execute([
        ':user_id' => $_SESSION['user_id'] ?? null,
        ':id' => $id,
    ]);

    $pdo->prepare('UPDATE records SET rec_status = "matched" WHERE id IN (:bank_id, :ledger_id)')
        ->execute([
            ':bank_id' => (int) $match['bank_id'],
            ':ledger_id' => (int) $match['ledger_id'],
        ]);

    $pdo->prepare(
    'UPDATE discrepancies
     SET status = "resolved", resolved_by = :user_id, resolved_at = NOW(), resolution_note = :note
     WHERE period = :period
       AND status IN ("needs_review", "pending")
       AND issue_type IN ("no_match", "duplicate", "date_gap")
       AND ((record_id = :bank_a AND counter_record_id = :ledger_a)
         OR (record_id = :ledger_b AND counter_record_id = :bank_b))'
    )->execute([
        ':user_id'  => $_SESSION['user_id'] ?? null,
        ':note'     => 'Match manually confirmed',
        ':period'   => $match['period'],
        ':bank_a'   => (int) $match['bank_id'],
        ':ledger_a' => (int) $match['ledger_id'],
        ':ledger_b' => (int) $match['ledger_id'],
        ':bank_b'   => (int) $match['bank_id'],
    ]);

    log_audit_event('Match confirmed', 'Matching', (string) $match['id'], 'success', 'Manual confirmation');
    $json = $pdo->prepare('SELECT * FROM matches WHERE id = :id LIMIT 1');
    $json->execute([':id' => $id]);
    $confirmedMatch = $json->fetch();
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

json_success($confirmedMatch);
