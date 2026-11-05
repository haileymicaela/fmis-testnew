<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/includes/database.php';
require_once __DIR__ . '/../backend/includes/support_matching.php';

$dryRun = in_array('--dry-run', $argv, true);
$refreshSeed = in_array('--refresh-seed', $argv, true);
// Preview with --dry-run; use --refresh-seed to replace generated seed records and their linked rows.
$pdo = db();
$descriptions = [
    'Student tuition and miscellaneous fee collection',
    'Scholarship stipend disbursement',
    'Student account refund',
    'Payroll and employee allowances',
    'Office supplies procurement',
    'Electricity and water utilities',
    'Campus facilities maintenance',
    'Laboratory equipment purchase',
    'Bank deposit of student collections',
    'Interfund transfer for campus operations',
    'Library materials acquisition',
    'Student organization activity fund',
];
$payees = [
    'CSPC Student Accounts',
    'North Campus Supply Cooperative',
    'Bicol Utility Services',
    'CSPC Scholarship Office',
    'Campus Facilities Unit',
    'Regional Learning Services',
    'CSPC Procurement Office',
    'Bicol Educational Resources',
];
$accountCodes = ['1010', '1105', '4010', '4020', '5010', '5020', '6100', '6200'];

$makeMonthData = static function (int $month) use ($descriptions, $payees, $accountCodes): array {
    $period = sprintf('2026-%02d', $month);
    $daysInMonth = (int) (new DateTimeImmutable($period . '-01'))->format('t');
    $records = [];
    $matches = [];
    $discrepancies = [];
    $discrepancySequence = 1;

    $record = static function (
        string $source,
        string $reference,
        ?string $crossReference,
        ?string $supportReference,
        string $date,
        string $amount,
        string $description,
        string $status,
        string $mergeStatus = 'merged'
    ) use (&$records, $period, $payees, $accountCodes): array {
        $row = [
            'source' => $source,
            'ref_no' => $reference,
            'cross_ref' => $crossReference,
            'support_ref' => $supportReference,
            'txn_date' => $date,
            'period' => $period,
            'amount' => $amount,
            'payee' => $payees[count($records) % count($payees)],
            'description' => $description,
            'account_code' => $accountCodes[count($records) % count($accountCodes)],
            'merge_status' => $mergeStatus,
            'rec_status' => $status,
            'file_source' => $source,
        ];
        $records[] = $row;
        return $row;
    };

    $addDiscrepancy = static function (
        array $record,
        ?array $counterpart,
        string $issue,
        int $sequence
    ) use (&$discrepancies, &$discrepancySequence, $period): void {
        $status = $sequence % 7 === 0 ? 'resolved' : ($sequence % 5 === 0 ? 'pending' : 'needs_review');
        $discrepancies[] = [
            'ref_code' => sprintf('REC-%04d', 9000 + ((int) substr($period, 5, 2) * 100) + $discrepancySequence++),
            'period' => $period,
            'record_ref' => $record['ref_no'],
            'counter_ref' => $counterpart['ref_no'] ?? null,
            'issue_type' => $issue,
            'status' => $status,
            'flagged_on' => $record['txn_date'],
            'assigned_to' => 1,
            'variance' => number_format(abs((float) $record['amount'] - (float) ($counterpart['amount'] ?? 0)), 2, '.', ''),
            'diagnosis' => $issue === 'date_gap'
                ? 'Shared reference and amount; transaction dates exceed the configured matching window.'
                : 'Synthetic demonstration discrepancy requiring accounting review.',
            'suggested_action' => $issue === 'duplicate'
                ? 'Verify the repeated source reference.'
                : 'Review source documents and supporting ledger references.',
        ];
    };

    $siasCount = 44 + ($month % 9);
    $voucherCount = 32 + (($month * 3) % 10);
    $supportReferences = ['sias' => [], 'voucher' => []];
    $supportRecordPositions = [];
    $supportOffsets = ['sias' => 0, 'voucher' => 0];

    foreach (['sias' => $siasCount, 'voucher' => $voucherCount] as $source => $count) {
        for ($index = 1; $index <= $count; $index++) {
            $day = 1 + (($index * 11 + $month * 5) % $daysInMonth);
            $date = sprintf('%s-%02d', $period, $day);
            $cents = 10000 + (($month * 8831 + $index * 10297 + $index * $index * 19) % 9000000);
            $reference = $source === 'sias'
                ? sprintf('OR-%05d', 90000 + ($month * 100) + $index)
                : sprintf('DV-2026-%04d', 9000 + ($month * 100) + $index);
            $description = $descriptions[($index * 3 + $month) % count($descriptions)];
            $status = 'pending';
            $sourceRecord = $record(
                $source,
                $reference,
                null,
                null,
                $date,
                number_format($cents / 100, 2, '.', ''),
                $description,
                $status
            );
            $supportReferences[$source][] = $sourceRecord['ref_no'];
            $supportRecordPositions[$sourceRecord['ref_no']] = count($records) - 1;
        }
    }

    $attachSupport = static function (string $source, string $ledgerDate, string $ledgerAmount, int $sequence, string $outcome) use (&$supportReferences, &$supportRecordPositions, &$supportOffsets, &$records, $daysInMonth): string {
        $supportReference = $supportReferences[$source][$supportOffsets[$source]++] ?? null;
        if ($supportReference === null) {
            throw new RuntimeException('Not enough ' . $source . ' records to provide one supporting document per ledger entry.');
        }

        $supportPosition = $supportRecordPositions[$supportReference];
        $supportDate = new DateTimeImmutable($ledgerDate);
        if ($outcome === 'possible') {
            $offset = ((int) $supportDate->format('j') + 3 <= $daysInMonth) ? 3 : -3;
            $supportDate = $supportDate->modify(($offset >= 0 ? '+' : '') . $offset . ' days');
        }
        $supportAmount = $ledgerAmount;
        if ($outcome === 'unmatched') {
            $supportAmount = number_format((float) $ledgerAmount + (100 + ($sequence % 5) * 25) / 100, 2, '.', '');
        }
        $records[$supportPosition]['txn_date'] = $supportDate->format('Y-m-d');
        $records[$supportPosition]['amount'] = $supportAmount;
        return $supportReference;
    };

    $pairCount = 40 + ($month % 5);
    for ($index = 1; $index <= $pairCount; $index++) {
        $category = $index % 10;
        $hasDuplicate = $index % 17 === 0;
        $result = $hasDuplicate ? 'duplicate' : ($category < 8 ? 'matched' : ($category === 8 ? 'possible' : 'unmatched'));
        $crossReference = sprintf('SYN26-X-%02d-%04d', $month, $index);
        $day = 4 + (($index * 7 + $month * 3) % max(1, $daysInMonth - 7));
        $bankDate = new DateTimeImmutable(sprintf('%s-%02d', $period, $day));
        $dateOffset = $result === 'possible' ? ($index % 2 === 0 ? 3 : -3) : ($index % 5 - 2);
        $ledgerDate = $bankDate->modify(($dateOffset >= 0 ? '+' : '') . $dateOffset . ' days');
        $baseCents = 25000 + (($month * 6817 + $index * 12937 + $index * $index * 73) % 15000000);
        $bankAmount = number_format($baseCents / 100, 2, '.', '');
        $ledgerAmount = $result === 'unmatched'
            ? number_format(($baseCents + ($index % 2 === 0 ? 7250 : 19350)) / 100, 2, '.', '')
            : $bankAmount;
        $sourceType = $index % 2 === 0 ? 'sias' : 'voucher';
        $supportOutcome = $index % 10 < 8 ? 'matched' : ($index % 10 === 8 ? 'possible' : 'unmatched');
        $supportReference = $attachSupport($sourceType, $ledgerDate->format('Y-m-d'), $ledgerAmount, $index, $supportOutcome);
        $description = $descriptions[($index * 5 + $month) % count($descriptions)];
        $bankSequence = 8000 + ($month * 100) + $index;
        $ledgerSequence = 8000 + ($month * 100) + $index;
        $bankReference = sprintf('BS-%02d%02d-%04d', $month, (int) $bankDate->format('d'), $bankSequence);
        $ledgerReference = sprintf('JV-2026-%04d', $ledgerSequence);
        $bank = $record(
            'bank',
            $bankReference,
            $crossReference,
            null,
            $bankDate->format('Y-m-d'),
            $bankAmount,
            $description,
            $result
        );
        $ledger = $record(
            'ledger',
            $ledgerReference,
            $crossReference,
            $supportReference,
            $ledgerDate->format('Y-m-d'),
            $ledgerAmount,
            $description,
            $result
        );
        $dateDiff = abs($dateOffset);
        $matches[] = [
            'bank_ref' => $bank['ref_no'],
            'ledger_ref' => $ledger['ref_no'],
            'amount' => $bankAmount,
            'a_ok' => (int) ($bankAmount === $ledgerAmount),
            'r_ok' => 1,
            'd_ok' => (int) ($dateDiff <= 2),
            'date_diff' => $dateDiff,
            'result' => $result,
        ];

        if ($result === 'possible') {
            $addDiscrepancy($bank, $ledger, 'date_gap', $index);
        } elseif ($result === 'unmatched') {
            $addDiscrepancy($bank, $ledger, 'no_match', $index);
        } elseif ($result === 'duplicate') {
            $addDiscrepancy($bank, $ledger, 'duplicate', $index);
            $duplicate = $record(
                'bank',
                sprintf('BS-%02d%02d-%04d', $month, (int) $bankDate->format('d'), 8800 + ($month * 100) + $index),
                $crossReference,
                null,
                $bankDate->format('Y-m-d'),
                $bankAmount,
                $description . ' (duplicate source entry)',
                'duplicate',
                'possible_duplicate'
            );
            $matches[] = [
                'bank_ref' => $duplicate['ref_no'],
                'ledger_ref' => $ledger['ref_no'],
                'amount' => $bankAmount,
                'a_ok' => 1,
                'r_ok' => 1,
                'd_ok' => 1,
                'date_diff' => 0,
                'result' => 'duplicate',
            ];
            $addDiscrepancy($duplicate, $ledger, 'duplicate', $index + $pairCount);
        }
    }

    $orphanCount = 4 + ($month % 3);
    for ($index = 1; $index <= $orphanCount; $index++) {
        $day = 1 + (($index * 9 + $month) % $daysInMonth);
        $date = sprintf('%s-%02d', $period, $day);
        $amount = number_format((18000 + (($index * $month * 1973) % 2500000)) / 100, 2, '.', '');

        foreach (['bank' => 'OB', 'ledger' => 'OL'] as $source => $suffix) {
            $crossReference = sprintf('SYN26-X-%02d-%s%02d', $month, $suffix, $index);
            $sequence = 9500 + ($month * 20) + $index;
            $reference = $source === 'bank'
                ? sprintf('BS-%02d%02d-%04d', $month, (int) $day, $sequence)
                : sprintf('JV-2026-%04d', $sequence);
            $supportReference = null;
            if ($source === 'ledger') {
                $sourceType = $index % 2 === 0 ? 'sias' : 'voucher';
                $supportOutcome = $index % 10 < 8 ? 'matched' : ($index % 10 === 8 ? 'possible' : 'unmatched');
                $supportReference = $attachSupport($sourceType, $date, $amount, $pairCount + $index, $supportOutcome);
            }
            $orphan = $record(
                $source,
                $reference,
                $crossReference,
                $supportReference,
                $date,
                $amount,
                $descriptions[($index + ($source === 'bank' ? 2 : 5)) % count($descriptions)],
                'unmatched'
            );
            $matches[] = [
                'bank_ref' => $source === 'bank' ? $orphan['ref_no'] : null,
                'ledger_ref' => $source === 'ledger' ? $orphan['ref_no'] : null,
                'amount' => $amount,
                'a_ok' => null,
                'r_ok' => null,
                'd_ok' => null,
                'date_diff' => null,
                'result' => 'unmatched',
            ];
            $addDiscrepancy($orphan, null, 'no_match', $pairCount + $index);
        }
    }

    return compact('period', 'records', 'matches', 'discrepancies');
};

$refreshSeedPeriod = static function (int $month) use ($pdo): int {
    $period = sprintf('2026-%02d', $month);
    $legacyMarker = sprintf('SYN26-%%-%02d-%%', $month);
    $filePattern = sprintf('synthetic_seed_2026_%02d_%%', $month);
    $seedRecordStmt = $pdo->prepare(
        'SELECT r.id FROM records r
         LEFT JOIN source_files sf ON sf.id = r.file_id
         WHERE r.period = :period AND (r.ref_no LIKE :legacy_marker OR sf.file_name LIKE :file_pattern)'
    );
    $seedRecordStmt->execute([':period' => $period, ':legacy_marker' => $legacyMarker, ':file_pattern' => $filePattern]);
    $seedRecordIds = array_map('intval', $seedRecordStmt->fetchAll(PDO::FETCH_COLUMN));

    if ($seedRecordIds !== []) {
        $makeIdList = static function (string $prefix) use ($seedRecordIds): array {
            $tokens = [];
            $params = [];
            foreach ($seedRecordIds as $index => $recordId) {
                $token = ':' . $prefix . $index;
                $tokens[] = $token;
                $params[$token] = $recordId;
            }
            return [implode(', ', $tokens), $params];
        };
        [$supportLedgerIds, $supportLedgerParams] = $makeIdList('support_ledger_');
        [$supportRecordIds, $supportRecordParams] = $makeIdList('support_record_');
        $supportTable = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'support_matches'")->fetchColumn();
        if ((int) $supportTable > 0) {
            $pdo->prepare('DELETE FROM support_matches WHERE ledger_id IN (' . $supportLedgerIds . ') OR support_record_id IN (' . $supportRecordIds . ')')->execute($supportLedgerParams + $supportRecordParams);
        }
        [$discrepancyPrimaryIds, $discrepancyPrimaryParams] = $makeIdList('discrepancy_primary_');
        [$discrepancyCounterIds, $discrepancyCounterParams] = $makeIdList('discrepancy_counter_');
        $pdo->prepare('DELETE FROM discrepancies WHERE record_id IN (' . $discrepancyPrimaryIds . ') OR counter_record_id IN (' . $discrepancyCounterIds . ')')->execute($discrepancyPrimaryParams + $discrepancyCounterParams);
        [$matchBankIds, $matchBankParams] = $makeIdList('match_bank_');
        [$matchLedgerIds, $matchLedgerParams] = $makeIdList('match_ledger_');
        $pdo->prepare('DELETE FROM matches WHERE bank_id IN (' . $matchBankIds . ') OR ledger_id IN (' . $matchLedgerIds . ')')->execute($matchBankParams + $matchLedgerParams);
        [$recordIds, $recordParams] = $makeIdList('record_');
        $pdo->prepare('DELETE FROM records WHERE id IN (' . $recordIds . ')')->execute($recordParams);
    }

    $pdo->prepare(
        'DELETE sf FROM source_files sf
         WHERE sf.period = :period AND sf.file_name LIKE :file_pattern
           AND NOT EXISTS (SELECT 1 FROM records r WHERE r.file_id = sf.id)'
    )->execute([
        ':period' => $period,
        ':file_pattern' => $filePattern,
    ]);

    return count($seedRecordIds);
};

$inserted = ['source_files' => 0, 'records' => 0, 'matches' => 0, 'discrepancies' => 0];
$skippedExistingPeriods = [];
$supportTolerance = 2;
if (!$dryRun) {
    ensure_support_matches_table($pdo);
    $toleranceStmt = $pdo->prepare('SELECT svalue FROM settings WHERE skey = "date_tolerance_days" LIMIT 1');
    $toleranceStmt->execute();
    $configuredTolerance = $toleranceStmt->fetchColumn();
    if ($configuredTolerance !== false) {
        $supportTolerance = max(0, (int) $configuredTolerance);
    }
}

for ($month = 1; $month <= 7; $month++) {
    $data = $makeMonthData($month);
    $period = $data['period'];
    $marker = sprintf('SYN26-%%-%02d-%%', $month);
    $existingCount = $pdo->prepare('SELECT COUNT(*) FROM records WHERE period = :period');
    $existingCount->execute([':period' => $period]);
    $existingCount = (int) $existingCount->fetchColumn();
    $filePattern = sprintf('synthetic_seed_2026_%02d_%%', $month);
    $syntheticCount = $pdo->prepare(
        'SELECT COUNT(*) FROM records r
         LEFT JOIN source_files sf ON sf.id = r.file_id
         WHERE r.period = :period AND (r.ref_no LIKE :legacy_marker OR sf.file_name LIKE :file_pattern)'
    );
    $syntheticCount->execute([':period' => $period, ':legacy_marker' => $marker, ':file_pattern' => $filePattern]);
    $syntheticCount = (int) $syntheticCount->fetchColumn();

    if ($month <= 2 && $existingCount > $syntheticCount && $syntheticCount === 0) {
        if (!$dryRun) {
            $supportProcessed = run_support_matching($pdo, $period, $supportTolerance);
            echo $period . ': computed ' . $supportProcessed . " supporting-document matches for preserved records\n";
        }
        $skippedExistingPeriods[] = $period;
        echo $period . ': preserved ' . $existingCount . " existing records (no seed changes)\n";
        continue;
    }

    if ($syntheticCount > 0 && !$refreshSeed) {
        echo $period . ': existing seeded records were preserved; use --refresh-seed to replace them with the current seed format' . "\n";
        continue;
    }

    if ($dryRun) {
        $missingRecords = 0;
        $exists = $pdo->prepare('SELECT 1 FROM records WHERE ref_no = :ref_no LIMIT 1');
        foreach ($data['records'] as $row) {
            $exists->execute([':ref_no' => $row['ref_no']]);
            $missingRecords += $exists->fetchColumn() === false ? 1 : 0;
        }
        echo $period . ': ' . $missingRecords . " records would be added";
        if ($refreshSeed && $syntheticCount > 0) {
            echo ' and ' . $syntheticCount . " existing synthetic records would be refreshed";
        }
        echo "\n";
        continue;
    }

    $pdo->beginTransaction();
    try {
        if ($refreshSeed && $syntheticCount > 0) {
            $refreshSeedPeriod($month);
        }

        $sourceFiles = [];
        $findFile = $pdo->prepare('SELECT id, source, period FROM source_files WHERE file_name = :file_name LIMIT 1');
        $createFile = $pdo->prepare(
            'INSERT INTO source_files (source, period, file_name, imported_at, imported_by, record_count, status)
             VALUES (:source, :period, :file_name, :imported_at, NULL, 0, "imported")'
        );
        foreach (['bank', 'ledger', 'sias', 'voucher'] as $source) {
            $fileName = sprintf('synthetic_seed_2026_%02d_%s.csv', $month, $source);
            $findFile->execute([':file_name' => $fileName]);
            $file = $findFile->fetch();
            if ($file && ($file['source'] !== $source || $file['period'] !== $period)) {
                throw new RuntimeException('A seed source-file reference conflicts with existing data: ' . $fileName);
            }
            if (!$file) {
                $days = (int) (new DateTimeImmutable($period . '-01'))->format('t');
                $createFile->execute([
                    ':source' => $source,
                    ':period' => $period,
                    ':file_name' => $fileName,
                    ':imported_at' => sprintf('%s-%02d 09:00:00', $period, $days),
                ]);
                $fileId = (int) $pdo->lastInsertId();
                $inserted['source_files']++;
            } else {
                $fileId = (int) $file['id'];
            }
            $sourceFiles[$source] = $fileId;
        }

        $findRecord = $pdo->prepare('SELECT id, source, period, amount FROM records WHERE ref_no = :ref_no LIMIT 1');
        $createRecord = $pdo->prepare(
            'INSERT INTO records
                (source, ref_no, cross_ref, support_ref, txn_date, period, amount, payee, description,
                 account_code, merge_status, rec_status, file_id)
             VALUES
                (:source, :ref_no, :cross_ref, :support_ref, :txn_date, :period, :amount, :payee, :description,
                 :account_code, :merge_status, :rec_status, :file_id)'
        );
        $recordIds = [];
        foreach ($data['records'] as $row) {
            $findRecord->execute([':ref_no' => $row['ref_no']]);
            $existing = $findRecord->fetch();
            if ($existing) {
                if ($existing['source'] !== $row['source']
                    || $existing['period'] !== $period
                    || (string) $existing['amount'] !== $row['amount']) {
                    throw new RuntimeException('A synthetic reference conflicts with existing data: ' . $row['ref_no']);
                }
                $recordIds[$row['ref_no']] = (int) $existing['id'];
                continue;
            }

            $createRecord->execute([
                ':source' => $row['source'],
                ':ref_no' => $row['ref_no'],
                ':cross_ref' => $row['cross_ref'],
                ':support_ref' => $row['support_ref'],
                ':txn_date' => $row['txn_date'],
                ':period' => $row['period'],
                ':amount' => $row['amount'],
                ':payee' => $row['payee'],
                ':description' => $row['description'],
                ':account_code' => $row['account_code'],
                ':merge_status' => $row['merge_status'],
                ':rec_status' => $row['rec_status'],
                ':file_id' => $sourceFiles[$row['file_source']],
            ]);
            $recordIds[$row['ref_no']] = (int) $pdo->lastInsertId();
            $inserted['records']++;
        }

        $findMatch = $pdo->prepare(
            'SELECT id FROM matches
             WHERE period = :period AND bank_id <=> :bank_id AND ledger_id <=> :ledger_id
             ORDER BY id LIMIT 1'
        );
        $createMatch = $pdo->prepare(
            'INSERT INTO matches
                (period, bank_id, ledger_id, amount, a_ok, r_ok, d_ok, date_diff, result, confirmed, note)
             VALUES
                (:period, :bank_id, :ledger_id, :amount, :a_ok, :r_ok, :d_ok, :date_diff, :result, 0, "Synthetic seed result")'
        );
        foreach ($data['matches'] as $match) {
            $bankId = $match['bank_ref'] === null ? null : $recordIds[$match['bank_ref']];
            $ledgerId = $match['ledger_ref'] === null ? null : $recordIds[$match['ledger_ref']];
            $findMatch->execute([':period' => $period, ':bank_id' => $bankId, ':ledger_id' => $ledgerId]);
            if ($findMatch->fetchColumn() !== false) {
                continue;
            }
            $createMatch->execute([
                ':period' => $period,
                ':bank_id' => $bankId,
                ':ledger_id' => $ledgerId,
                ':amount' => $match['amount'],
                ':a_ok' => $match['a_ok'],
                ':r_ok' => $match['r_ok'],
                ':d_ok' => $match['d_ok'],
                ':date_diff' => $match['date_diff'],
                ':result' => $match['result'],
            ]);
            $inserted['matches']++;
        }

        $findDiscrepancy = $pdo->prepare('SELECT id FROM discrepancies WHERE ref_code = :ref_code LIMIT 1');
        $createDiscrepancy = $pdo->prepare(
            'INSERT INTO discrepancies
                (ref_code, period, record_id, counter_record_id, issue_type, status, flagged_on, flagged_at,
                 assigned_to, variance, diagnosis, suggested_action, resolved_by, resolved_at, resolution_note)
             VALUES
                (:ref_code, :period, :record_id, :counter_record_id, :issue_type, :status, :flagged_on, :flagged_at,
                 :assigned_to, :variance, :diagnosis, :suggested_action, :resolved_by, :resolved_at, :resolution_note)'
        );
        foreach ($data['discrepancies'] as $discrepancy) {
            $findDiscrepancy->execute([':ref_code' => $discrepancy['ref_code']]);
            if ($findDiscrepancy->fetchColumn() !== false) {
                continue;
            }
            $resolved = $discrepancy['status'] === 'resolved';
            $createDiscrepancy->execute([
                ':ref_code' => $discrepancy['ref_code'],
                ':period' => $period,
                ':record_id' => $recordIds[$discrepancy['record_ref']],
                ':counter_record_id' => $discrepancy['counter_ref'] === null ? null : $recordIds[$discrepancy['counter_ref']],
                ':issue_type' => $discrepancy['issue_type'],
                ':status' => $discrepancy['status'],
                ':flagged_on' => $discrepancy['flagged_on'],
                ':flagged_at' => $discrepancy['flagged_on'] . ' 09:00:00',
                ':assigned_to' => $discrepancy['assigned_to'],
                ':variance' => $discrepancy['variance'],
                ':diagnosis' => $discrepancy['diagnosis'],
                ':suggested_action' => $discrepancy['suggested_action'],
                ':resolved_by' => $resolved ? 1 : null,
                ':resolved_at' => $resolved ? $discrepancy['flagged_on'] . ' 16:00:00' : null,
                ':resolution_note' => $resolved ? 'Synthetic demonstration case marked resolved.' : null,
            ]);
            $inserted['discrepancies']++;
        }

        $updateFileCount = $pdo->prepare('UPDATE source_files SET record_count = :count WHERE id = :id');
        foreach ($sourceFiles as $source => $fileId) {
            $count = $pdo->prepare('SELECT COUNT(*) FROM records WHERE file_id = :file_id');
            $count->execute([':file_id' => $fileId]);
            $updateFileCount->execute([':count' => (int) $count->fetchColumn(), ':id' => $fileId]);
        }

        $supportProcessed = run_support_matching($pdo, $period, $supportTolerance);
        $pdo->commit();
        $monthCount = count($data['records']);
        echo $period . ': inserted or verified ' . $monthCount . ' deterministic records and computed ' . $supportProcessed . " supporting-document matches\n";
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

if ($refreshSeed) {
    for ($month = 8; $month <= 12; $month++) {
        $period = sprintf('2026-%02d', $month);
        $marker = sprintf('SYN26-%%-%02d-%%', $month);
        $filePattern = sprintf('synthetic_seed_2026_%02d_%%', $month);
        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM records r
             LEFT JOIN source_files sf ON sf.id = r.file_id
             WHERE r.period = :period AND (r.ref_no LIKE :legacy_marker OR sf.file_name LIKE :file_pattern)'
        );
        $countStmt->execute([':period' => $period, ':legacy_marker' => $marker, ':file_pattern' => $filePattern]);
        $count = (int) $countStmt->fetchColumn();
        if ($dryRun) {
            if ($count > 0) {
                echo $period . ': ' . $count . " out-of-range seeded records would be removed\n";
            }
            continue;
        }
        $pdo->beginTransaction();
        try {
            $removed = $refreshSeedPeriod($month);
            $pdo->commit();
            if ($removed > 0) {
                echo $period . ': removed ' . $removed . " out-of-range seeded records\n";
            }
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }
}

if (!$dryRun) {
    $asOfDate = $pdo->prepare('UPDATE settings SET svalue = "2026-07-31" WHERE skey = "as_of_date" AND svalue IN ("2026-02-18", "2026-10-31")');
    $asOfDate->execute();
    if ($asOfDate->rowCount() > 0) {
        echo "Advanced the unchanged demo as-of date to 2026-07-31; the computer clock was not modified.\n";
    }
    echo 'Inserted: ' . json_encode($inserted, JSON_UNESCAPED_SLASHES) . "\n";
}

if ($skippedExistingPeriods !== []) {
    echo 'Preserved existing periods: ' . implode(', ', $skippedExistingPeriods) . "\n";
}
