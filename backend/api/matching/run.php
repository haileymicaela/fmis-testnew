<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/support_matching.php';

require_method('POST');
require_auth();
check_csrf_if_present();

$payload = read_json_input();
$period = get_period_value((string) ($payload['period'] ?? $_POST['period'] ?? ''));
$pdo = db();
$toleranceDays = (int) get_setting('date_tolerance_days', 2);
ensure_support_matches_table($pdo);

$pdo->beginTransaction();

try {
    $recordsStmt = $pdo->prepare(
        'SELECT id, source, cross_ref, amount, txn_date
         FROM records
         WHERE period = :period AND source IN ("bank", "ledger")
         ORDER BY id'
    );
    $recordsStmt->execute([':period' => $period]);
    $records = $recordsStmt->fetchAll();

    $matchesStmt = $pdo->prepare('SELECT * FROM matches WHERE period = :period ORDER BY id');
    $matchesStmt->execute([':period' => $period]);
    $existingMatches = [];
    $confirmedRecords = [];
    foreach ($matchesStmt->fetchAll() as $match) {
        $key = ($match['bank_id'] ?? 'null') . ':' . ($match['ledger_id'] ?? 'null');
        $existingMatches[$key] ??= $match;
        if ((int) $match['confirmed'] === 1) {
            if ($match['bank_id'] !== null) {
                $confirmedRecords[(int) $match['bank_id']] = true;
            }
            if ($match['ledger_id'] !== null) {
                $confirmedRecords[(int) $match['ledger_id']] = true;
            }
        }
    }

    $pdo->prepare(
        'UPDATE records SET rec_status = "unmatched"
         WHERE period = :period AND source IN ("bank", "ledger")'
    )->execute([':period' => $period]);
    foreach (array_keys($confirmedRecords) as $recordId) {
        $pdo->prepare('UPDATE records SET rec_status = "matched" WHERE id = :id')
            ->execute([':id' => $recordId]);
    }

    $groups = [];
    foreach ($records as $record) {
        $groupKey = $record['cross_ref'] === null || trim((string) $record['cross_ref']) === ''
            ? 'missing:' . $record['source'] . ':' . $record['id']
            : 'cross:' . trim((string) $record['cross_ref']);
        $groups[$groupKey][$record['source']][] = $record;
    }

    $results = [];
    $insert = $pdo->prepare(
        'INSERT INTO matches
            (period, bank_id, ledger_id, amount, a_ok, r_ok, d_ok, date_diff, result, confirmed, note)
         VALUES
            (:period, :bank_id, :ledger_id, :amount, :a_ok, :r_ok, :d_ok, :date_diff, :result, 0, :note)'
    );
    $update = $pdo->prepare(
        'UPDATE matches
         SET amount = :amount, a_ok = :a_ok, r_ok = :r_ok, d_ok = :d_ok,
             date_diff = :date_diff, result = :result, note = :note
         WHERE id = :id AND confirmed = 0'
    );
    $updateRecord = $pdo->prepare('UPDATE records SET rec_status = :status WHERE id = :id');
    $findDateGap = $pdo->prepare(
        'SELECT id FROM discrepancies
         WHERE period = :period AND issue_type = "date_gap"
           AND ((record_id = :bank_a AND counter_record_id = :ledger_a)
             OR (record_id = :ledger_b AND counter_record_id = :bank_b))
         LIMIT 1'
    );
    $insertDateGap = $pdo->prepare(
        'INSERT INTO discrepancies
            (ref_code, period, record_id, counter_record_id, issue_type, status,
             flagged_on, flagged_at, assigned_to, variance, diagnosis, suggested_action)
         VALUES
            (:ref_code, :period, :record_id, :counter_record_id, "date_gap", "needs_review",
             :flagged_on, :flagged_at, :assigned_to, :variance, :diagnosis, :suggested_action)'
    );
    $findGeneratedDiscrepancy = $pdo->prepare(
        'SELECT id FROM discrepancies
         WHERE period = :period AND issue_type = :issue_type
           AND ((record_id = :record_a AND counter_record_id <=> :counter_a)
             OR (record_id = :record_b AND counter_record_id <=> :counter_b))
         LIMIT 1'
    );
    $insertGeneratedDiscrepancy = $pdo->prepare(
        'INSERT INTO discrepancies
            (ref_code, period, record_id, counter_record_id, issue_type, status,
             flagged_on, flagged_at, assigned_to, variance, diagnosis, suggested_action)
         VALUES
            (:ref_code, :period, :record_id, :counter_record_id, :issue_type, "needs_review",
             :flagged_on, :flagged_at, :assigned_to, :variance, :diagnosis, :suggested_action)'
    );

    $saveComparison = static function (
        ?array $bank,
        ?array $ledger,
        string $result,
        ?bool $amountOk,
        ?bool $referenceOk,
        ?bool $dateOk,
        ?int $dateDiff,
        array $statusRecordIds
    ) use ($period, $pdo, $existingMatches, $confirmedRecords, $insert, $update, $updateRecord, $findDateGap, $insertDateGap, $findGeneratedDiscrepancy, $insertGeneratedDiscrepancy, $toleranceDays, &$results): void {
        $bankId = $bank === null ? null : (int) $bank['id'];
        $ledgerId = $ledger === null ? null : (int) $ledger['id'];
        $key = ($bankId ?? 'null') . ':' . ($ledgerId ?? 'null');
        $existing = $existingMatches[$key] ?? null;

        if (($existing !== null && (int) $existing['confirmed'] === 1)
            || ($bankId !== null && isset($confirmedRecords[$bankId]))
            || ($ledgerId !== null && isset($confirmedRecords[$ledgerId]))) {
            return;
        }

        $amount = $bank['amount'] ?? $ledger['amount'] ?? null;
        $values = [
            ':period' => $period,
            ':bank_id' => $bankId,
            ':ledger_id' => $ledgerId,
            ':amount' => $amount,
            ':a_ok' => $amountOk === null ? null : (int) $amountOk,
            ':r_ok' => $referenceOk === null ? null : (int) $referenceOk,
            ':d_ok' => $dateOk === null ? null : (int) $dateOk,
            ':date_diff' => $dateDiff,
            ':result' => $result,
            ':note' => '',
        ];

        if ($existing === null) {
            $insert->execute($values);
            $matchId = (int) $pdo->lastInsertId();
            $existingMatches[$key] = ['id' => $matchId, 'confirmed' => 0];
        } else {
            $matchId = (int) $existing['id'];
            $update->execute([
                ':amount' => $values[':amount'],
                ':a_ok' => $values[':a_ok'],
                ':r_ok' => $values[':r_ok'],
                ':d_ok' => $values[':d_ok'],
                ':date_diff' => $values[':date_diff'],
                ':result' => $result,
                ':note' => '',
                ':id' => (int) $existing['id'],
            ]);
        }

        if ($result === 'possible' && $bank !== null && $ledger !== null) {
            $findDateGap->execute([
                ':period' => $period,
                ':bank_a' => $bankId,
                ':ledger_a' => $ledgerId,
                ':ledger_b' => $ledgerId,
                ':bank_b' => $bankId,
            ]);
            if ($findDateGap->fetchColumn() === false) {
                $flaggedAt = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
                $insertDateGap->execute([
                    ':ref_code' => 'DG' . str_pad((string) $matchId, 10, '0', STR_PAD_LEFT),
                    ':period' => $period,
                    ':record_id' => $bankId,
                    ':counter_record_id' => $ledgerId,
                    ':flagged_on' => $bank['txn_date'],
                    ':flagged_at' => $flaggedAt->format('Y-m-d H:i:s'),
                    ':assigned_to' => $_SESSION['user_id'] ?? null,
                    ':variance' => abs((float) $bank['amount'] - (float) $ledger['amount']),
                    ':diagnosis' => 'Bank and ledger dates differ by ' . $dateDiff . ' day(s), exceeding the ' . $toleranceDays . '-day tolerance.',
                    ':suggested_action' => 'Review the bank and ledger transaction dates.',
                ]);
            }
        }

        if ($result === 'unmatched' || $result === 'duplicate') {
            $issueType = $result === 'unmatched' ? 'no_match' : 'duplicate';
            $findGeneratedDiscrepancy->execute([
                ':period' => $period,
                ':issue_type' => $issueType,
                ':record_a' => $bankId ?? $ledgerId,
                ':counter_a' => $ledgerId,
                ':record_b' => $ledgerId ?? $bankId,
                ':counter_b' => $bankId,
            ]);
            if ($findGeneratedDiscrepancy->fetchColumn() === false) {
                $primary = $bank ?? $ledger;
                $other = $bank !== null && $ledger !== null ? $ledger : null;
                $flaggedAt = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
                $prefix = $issueType === 'no_match' ? 'NM' : 'DP';
                $diagnosis = $issueType === 'no_match'
                    ? ($other === null
                        ? 'No counterpart was found for ' . ($primary['cross_ref'] ?: ('record ' . $primary['id'])) . '.'
                        : 'Bank and ledger records share a reference but fail the matching criteria.')
                    : 'Multiple bank or ledger records share the same reference group.';
                $suggestedAction = $issueType === 'no_match'
                    ? 'Locate the missing counterpart or correct the transaction reference.'
                    : 'Compare the duplicate records and retain the valid posting.';
                $insertGeneratedDiscrepancy->execute([
                    ':ref_code' => $prefix . str_pad((string) $matchId, 10, '0', STR_PAD_LEFT),
                    ':period' => $period,
                    ':record_id' => $bankId ?? $ledgerId,
                    ':counter_record_id' => $ledgerId,
                    ':issue_type' => $issueType,
                    ':flagged_on' => $primary['txn_date'],
                    ':flagged_at' => $flaggedAt->format('Y-m-d H:i:s'),
                    ':assigned_to' => $_SESSION['user_id'] ?? null,
                    ':variance' => $bank !== null && $ledger !== null
                        ? abs((float) $bank['amount'] - (float) $ledger['amount'])
                        : 0,
                    ':diagnosis' => $diagnosis,
                    ':suggested_action' => $suggestedAction,
                ]);
            }
        }

        foreach ($statusRecordIds as $recordId) {
            if (!isset($confirmedRecords[$recordId])) {
                $updateRecord->execute([':status' => $result, ':id' => $recordId]);
            }
        }
        $results[] = $result;
    };

    foreach ($groups as $group) {
        $banks = $group['bank'] ?? [];
        $ledgers = $group['ledger'] ?? [];
        $hasDuplicates = count($banks) > 1 || count($ledgers) > 1;

        if ($banks !== [] && $ledgers !== []) {
            $bank = $banks[0];
            $ledger = $ledgers[0];
            $amountOk = $bank['amount'] !== null
                && $ledger['amount'] !== null
                && (float) $bank['amount'] === (float) $ledger['amount'];
            $referenceOk = trim((string) $bank['cross_ref']) !== ''
                && trim((string) $bank['cross_ref']) === trim((string) $ledger['cross_ref']);
            $dateDiff = abs((int) (new DateTimeImmutable((string) $bank['txn_date']))
                ->diff(new DateTimeImmutable((string) $ledger['txn_date']))->days);
            $dateOk = $dateDiff <= $toleranceDays;

            if ($hasDuplicates) {
                $saveComparison($bank, $ledger, 'duplicate', $amountOk, $referenceOk, $dateOk, $dateDiff, [
                    (int) $bank['id'],
                    (int) $ledger['id'],
                ]);
                foreach (array_slice($banks, 1) as $duplicateBank) {
                    $saveComparison($duplicateBank, $ledger, 'duplicate', null, true, null, null, [
                        (int) $duplicateBank['id'],
                    ]);
                }
                foreach (array_slice($ledgers, 1) as $duplicateLedger) {
                    $saveComparison($bank, $duplicateLedger, 'duplicate', null, true, null, null, [
                        (int) $duplicateLedger['id'],
                    ]);
                }
                continue;
            }

            $result = $amountOk && $referenceOk && $dateOk
                ? 'matched'
                : ($amountOk && $referenceOk ? 'possible' : 'unmatched');
            $saveComparison($bank, $ledger, $result, $amountOk, $referenceOk, $dateOk, $dateDiff, [
                (int) $bank['id'],
                (int) $ledger['id'],
            ]);
            continue;
        }

        foreach (array_merge($banks, $ledgers) as $record) {
            $isDuplicate = $hasDuplicates && $record !== ($banks[0] ?? $ledgers[0]);
            $result = $isDuplicate ? 'duplicate' : 'unmatched';
            $saveComparison(
                $record['source'] === 'bank' ? $record : null,
                $record['source'] === 'ledger' ? $record : null,
                $result,
                null,
                null,
                null,
                null,
                [(int) $record['id']]
            );
        }
    }

    $supportProcessed = run_support_matching($pdo, $period, $toleranceDays);

    $counts = array_count_values($results);
    $pdo->prepare(
        'INSERT INTO match_runs (period, run_at, run_by, total, matched, possible, unmatched, duplicates)
         VALUES (:period, NOW(), :user_id, :total, :matched, :possible, :unmatched, :duplicates)'
    )->execute([
        ':period' => $period,
        ':user_id' => $_SESSION['user_id'] ?? null,
        ':total' => count($results),
        ':matched' => $counts['matched'] ?? 0,
        ':possible' => $counts['possible'] ?? 0,
        ':unmatched' => $counts['unmatched'] ?? 0,
        ':duplicates' => $counts['duplicate'] ?? 0,
    ]);

    log_audit_event('Matching completed', 'Matching', 'RUN-' . $period, 'success', count($results) . ' bank-ledger comparisons and ' . $supportProcessed . ' supporting-document checks processed');
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

$statsStmt = $pdo->prepare(
    'SELECT COUNT(*) AS total,
            SUM(CASE WHEN rec_status = "matched" THEN 1 ELSE 0 END) AS matched,
            SUM(CASE WHEN rec_status = "possible" THEN 1 ELSE 0 END) AS possible,
            SUM(CASE WHEN rec_status = "unmatched" THEN 1 ELSE 0 END) AS unmatched,
            SUM(CASE WHEN rec_status = "duplicate" THEN 1 ELSE 0 END) AS duplicate,
            SUM(CASE WHEN rec_status = "pending" THEN 1 ELSE 0 END) AS pending
     FROM records WHERE period = :period'
);
$statsStmt->execute([':period' => $period]);
$stats = $statsStmt->fetch();

json_success([
    'processed' => count($results),
    'support_processed' => $supportProcessed,
    'stats' => [
        'total' => (int) ($stats['total'] ?? 0),
        'matched' => (int) ($stats['matched'] ?? 0),
        'possible' => (int) ($stats['possible'] ?? 0),
        'unmatched' => (int) ($stats['unmatched'] ?? 0),
        'duplicate' => (int) ($stats['duplicate'] ?? 0),
        'pending' => (int) ($stats['pending'] ?? 0),
        'rate' => $stats['total'] ? round(((int) $stats['matched'] / (int) $stats['total']) * 100, 2) : 0,
    ],
]);
