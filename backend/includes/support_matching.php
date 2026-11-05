<?php

declare(strict_types=1);

function ensure_support_matches_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS support_matches (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            period CHAR(7) NOT NULL,
            ledger_id INT UNSIGNED NOT NULL,
            support_record_id INT UNSIGNED NULL,
            amount_ok TINYINT NULL,
            reference_ok TINYINT(1) NOT NULL DEFAULT 0,
            date_ok TINYINT NULL,
            date_diff SMALLINT NULL,
            result ENUM("matched", "possible", "unmatched", "duplicate") NOT NULL,
            confirmed TINYINT(1) NOT NULL DEFAULT 0,
            confirmed_by INT UNSIGNED NULL,
            confirmed_at DATETIME NULL,
            note VARCHAR(200) NULL,
            UNIQUE KEY uq_support_match_ledger (ledger_id),
            KEY idx_support_match_period (period, result),
            KEY idx_support_match_record (support_record_id),
            CONSTRAINT fk_support_match_ledger FOREIGN KEY (ledger_id) REFERENCES records(id),
            CONSTRAINT fk_support_match_record FOREIGN KEY (support_record_id) REFERENCES records(id),
            CONSTRAINT fk_support_match_user FOREIGN KEY (confirmed_by) REFERENCES users(id)
        ) ENGINE=InnoDB'
    );
}

function run_support_matching(PDO $pdo, string $period, int $toleranceDays): int
{
    $ledgerStmt = $pdo->prepare(
        'SELECT id, support_ref, amount, txn_date
         FROM records
         WHERE period = :period AND source = "ledger"
         ORDER BY id'
    );
    $ledgerStmt->execute([':period' => $period]);
    $ledgerRecords = $ledgerStmt->fetchAll();

    $supportStmt = $pdo->prepare(
        'SELECT id, source, ref_no, amount, txn_date
         FROM records
         WHERE period = :period AND source IN ("sias", "voucher")
         ORDER BY id'
    );
    $supportStmt->execute([':period' => $period]);
    $supportByReference = [];
    foreach ($supportStmt->fetchAll() as $support) {
        $supportByReference[trim((string) $support['ref_no'])][] = $support;
    }

    $ledgerUsage = [];
    foreach ($ledgerRecords as $ledger) {
        $reference = trim((string) ($ledger['support_ref'] ?? ''));
        if ($reference !== '') {
            $ledgerUsage[$reference] = ($ledgerUsage[$reference] ?? 0) + 1;
        }
    }

    $existingStmt = $pdo->prepare('SELECT * FROM support_matches WHERE period = :period');
    $existingStmt->execute([':period' => $period]);
    $existingByLedger = [];
    foreach ($existingStmt->fetchAll() as $existing) {
        $existingByLedger[(int) $existing['ledger_id']] = $existing;
    }

    $insertMatch = $pdo->prepare(
        'INSERT INTO support_matches
            (period, ledger_id, support_record_id, amount_ok, reference_ok, date_ok, date_diff, result, note)
         VALUES
            (:period, :ledger_id, :support_record_id, :amount_ok, :reference_ok, :date_ok, :date_diff, :result, :note)'
    );
    $updateMatch = $pdo->prepare(
        'UPDATE support_matches
         SET support_record_id = :support_record_id, amount_ok = :amount_ok,
             reference_ok = :reference_ok, date_ok = :date_ok, date_diff = :date_diff,
             result = :result, note = :note
         WHERE id = :id AND confirmed = 0'
    );
    $updateSupportStatus = $pdo->prepare('UPDATE records SET rec_status = :status WHERE id = :id');
    $findDiscrepancy = $pdo->prepare(
        'SELECT id FROM discrepancies
         WHERE period = :period AND issue_type = :issue_type
           AND ((record_id = :record_a AND counter_record_id <=> :counter_a)
             OR (record_id = :record_b AND counter_record_id <=> :counter_b))
         LIMIT 1'
    );
    $insertDiscrepancy = $pdo->prepare(
        'INSERT INTO discrepancies
            (ref_code, period, record_id, counter_record_id, issue_type, status,
             flagged_on, flagged_at, assigned_to, variance, diagnosis, suggested_action)
         VALUES
            (:ref_code, :period, :record_id, :counter_record_id, :issue_type, "needs_review",
             :flagged_on, :flagged_at, :assigned_to, :variance, :diagnosis, :suggested_action)'
    );

    $processed = 0;
    foreach ($ledgerRecords as $ledger) {
        $ledgerId = (int) $ledger['id'];
        $existing = $existingByLedger[$ledgerId] ?? null;
        if ($existing !== null && (int) $existing['confirmed'] === 1) {
            continue;
        }

        $reference = trim((string) ($ledger['support_ref'] ?? ''));
        $candidates = $reference === '' ? [] : ($supportByReference[$reference] ?? []);
        $support = $candidates[0] ?? null;
        $supportId = $support === null ? null : (int) $support['id'];
        $amountOk = $support === null || $ledger['amount'] === null || $support['amount'] === null
            ? null
            : (int) ((float) $ledger['amount'] === (float) $support['amount']);
        $dateDiff = $support === null
            ? null
            : abs((int) (new DateTimeImmutable((string) $ledger['txn_date']))
                ->diff(new DateTimeImmutable((string) $support['txn_date']))->days);
        $dateOk = $dateDiff === null ? null : (int) ($dateDiff <= $toleranceDays);
        $referenceOk = $support === null ? 0 : 1;
        $isDuplicate = count($candidates) > 1 || ($reference !== '' && ($ledgerUsage[$reference] ?? 0) > 1);

        if ($isDuplicate) {
            $result = 'duplicate';
        } elseif ($amountOk === 1 && $dateOk === 1) {
            $result = 'matched';
        } elseif ($amountOk === 1 && $dateOk === 0) {
            $result = 'possible';
        } else {
            $result = 'unmatched';
        }

        $note = $support === null
            ? 'No SIAS or voucher record found for ledger support reference ' . ($reference !== '' ? $reference : '(missing)') . '.'
            : ($isDuplicate
                ? 'This supporting record reference is used by multiple ledger entries.'
                : ($amountOk !== 1
                    ? 'Ledger and supporting record amounts do not agree.'
                    : ($dateOk !== 1
                        ? 'Ledger and supporting record dates exceed the configured tolerance.'
                        : 'Supporting reference, amount, and date agree.')));
        $values = [
            ':period' => $period,
            ':ledger_id' => $ledgerId,
            ':support_record_id' => $supportId,
            ':amount_ok' => $amountOk,
            ':reference_ok' => $referenceOk,
            ':date_ok' => $dateOk,
            ':date_diff' => $dateDiff,
            ':result' => $result,
            ':note' => $note,
        ];

        if ($existing === null) {
            $insertMatch->execute($values);
            $supportMatchId = (int) $pdo->lastInsertId();
        } else {
            $supportMatchId = (int) $existing['id'];
            $updateMatch->execute([
                ':support_record_id' => $supportId,
                ':amount_ok' => $amountOk,
                ':reference_ok' => $referenceOk,
                ':date_ok' => $dateOk,
                ':date_diff' => $dateDiff,
                ':result' => $result,
                ':note' => $note,
                ':id' => $supportMatchId,
            ]);
        }

        if ($supportId !== null) {
            $updateSupportStatus->execute([':status' => $result, ':id' => $supportId]);
        }

        if ($result !== 'matched') {
            $issueType = $result === 'duplicate'
                ? 'duplicate'
                : ($result === 'possible' ? 'date_gap' : 'no_match');
            $findDiscrepancy->execute([
                ':period' => $period,
                ':issue_type' => $issueType,
                ':record_a' => $ledgerId,
                ':counter_a' => $supportId,
                ':record_b' => $supportId ?? $ledgerId,
                ':counter_b' => $supportId === null ? null : $ledgerId,
            ]);

            if ($findDiscrepancy->fetchColumn() === false) {
                $flaggedAt = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
                if ($result === 'duplicate') {
                    $diagnosis = 'A SIAS or voucher reference is linked to more than one ledger entry.';
                } elseif ($result === 'possible') {
                    $diagnosis = 'The supporting reference and amount agree, but the dates exceed the ' . $toleranceDays . '-day tolerance.';
                } else {
                    $diagnosis = $support === null
                        ? 'No SIAS or voucher record was found for ledger support reference ' . ($reference !== '' ? $reference : '(missing)') . '.'
                        : 'The supporting record amount differs from the ledger amount.';
                }
                $insertDiscrepancy->execute([
                    ':ref_code' => 'SM' . str_pad((string) $supportMatchId, 10, '0', STR_PAD_LEFT),
                    ':period' => $period,
                    ':record_id' => $ledgerId,
                    ':counter_record_id' => $supportId,
                    ':issue_type' => $issueType,
                    ':flagged_on' => $ledger['txn_date'],
                    ':flagged_at' => $flaggedAt->format('Y-m-d H:i:s'),
                    ':assigned_to' => $_SESSION['user_id'] ?? null,
                    ':variance' => $support !== null && $ledger['amount'] !== null && $support['amount'] !== null
                        ? abs((float) $ledger['amount'] - (float) $support['amount'])
                        : 0,
                    ':diagnosis' => $diagnosis,
                    ':suggested_action' => $result === 'possible'
                        ? 'Review the ledger and supporting document dates.'
                        : ($result === 'duplicate'
                            ? 'Confirm which ledger entry the supporting document belongs to.'
                            : 'Locate the correct SIAS or voucher document and update the ledger support reference.'),
                ]);
            }
        }

        $processed++;
    }

    return $processed;
}
