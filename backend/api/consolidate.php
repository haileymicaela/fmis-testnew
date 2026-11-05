<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staging.php';

require_method('POST');
require_auth();
check_csrf_if_present();

$payload = read_json_input();
$period = get_period_value((string) ($payload['period'] ?? $_POST['period'] ?? ''));
$pdo = db();
ensure_staging_tables($pdo);
$pdo->beginTransaction();

try {
    $importsStmt = $pdo->prepare(
        'SELECT * FROM staged_imports
         WHERE period = :period AND status = "staged"
         ORDER BY staged_at, id FOR UPDATE'
    );
    $importsStmt->execute([':period' => $period]);
    $imports = $importsStmt->fetchAll();
    if ($imports === []) {
        $pdo->commit();
        json_success(['period' => $period, 'files' => 0, 'imported' => 0, 'duplicates' => 0, 'rejected' => 0]);
    }

    $rowStmt = $pdo->prepare('SELECT * FROM staged_records WHERE staged_import_id = :id ORDER BY id');
    $existingRecord = $pdo->prepare('SELECT id FROM records WHERE ref_no = :ref_no LIMIT 1');
    $createSourceFile = $pdo->prepare(
        'INSERT INTO source_files (source, period, file_name, imported_at, imported_by, record_count, status)
         VALUES (:source, :period, :file_name, NOW(), :user_id, 0, "imported")'
    );
    $createRecord = $pdo->prepare(
        'INSERT INTO records
            (source, ref_no, cross_ref, support_ref, txn_date, period, amount, payee, description,
             account_code, merge_status, rec_status, file_id)
         VALUES
            (:source, :ref_no, :cross_ref, :support_ref, :txn_date, :period, :amount, :payee, :description,
             :account_code, "merged", "pending", :file_id)'
    );
    $updateSourceFile = $pdo->prepare('UPDATE source_files SET record_count = :count WHERE id = :id');
    $finishStage = $pdo->prepare(
        'UPDATE staged_imports
         SET status = "consolidated", imported_count = :imported, duplicate_count = duplicate_count + :duplicates
         WHERE id = :id'
    );

    $seenRefs = [];
    $imported = 0;
    $duplicates = 0;
    $rejected = 0;
    $consolidatedFiles = 0;
    foreach ($imports as $stage) {
        $rowStmt->execute([':id' => $stage['id']]);
        $rows = $rowStmt->fetchAll();
        $fileRows = [];
        $fileDuplicates = 0;
        foreach ($rows as $row) {
            $refNo = (string) $row['ref_no'];
            $existingRecord->execute([':ref_no' => $refNo]);
            if (isset($seenRefs[$refNo]) || $existingRecord->fetchColumn() !== false) {
                $fileDuplicates++;
                continue;
            }
            $seenRefs[$refNo] = true;
            $fileRows[] = $row;
        }

        if ($fileRows !== []) {
            $createSourceFile->execute([
                ':source' => $stage['source'],
                ':period' => $period,
                ':file_name' => $stage['file_name'],
                ':user_id' => $_SESSION['user_id'] ?? null,
            ]);
            $fileId = (int) $pdo->lastInsertId();
            foreach ($fileRows as $row) {
                $createRecord->execute([
                    ':source' => $stage['source'],
                    ':ref_no' => $row['ref_no'],
                    ':cross_ref' => $row['cross_ref'],
                    ':support_ref' => $row['support_ref'],
                    ':txn_date' => $row['txn_date'],
                    ':period' => $period,
                    ':amount' => $row['amount'],
                    ':payee' => $row['payee'],
                    ':description' => $row['description'],
                    ':account_code' => $row['account_code'],
                    ':file_id' => $fileId,
                ]);
            }
            $updateSourceFile->execute([':count' => count($fileRows), ':id' => $fileId]);
            $imported += count($fileRows);
            $consolidatedFiles++;
        }

        $duplicates += (int) $stage['duplicate_count'] + $fileDuplicates;
        $rejected += (int) $stage['rejected_count'];
        $finishStage->execute([
            ':imported' => count($fileRows),
            ':duplicates' => $fileDuplicates,
            ':id' => $stage['id'],
        ]);
    }

    log_audit_event(
        'Records consolidated',
        'Consolidation',
        $period,
        'success',
        $imported . ' records consolidated; ' . $duplicates . ' duplicates skipped; ' . $rejected . ' rows rejected'
    );
    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $error;
}

json_success([
    'period' => $period,
    'files' => $consolidatedFiles,
    'imported' => $imported,
    'duplicates' => $duplicates,
    'rejected' => $rejected,
]);
