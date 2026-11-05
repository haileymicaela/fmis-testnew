<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/staging.php';

require_method('POST');
require_auth();
check_csrf_if_present();

function import_response_error(string $message, array $data, int $status = 422): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
        ['success' => false, 'message' => $message, 'data' => $data],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function import_csv_rows(string $path): array
{
    $handle = fopen($path, 'r');
    if ($handle === false) {
        import_response_error('Unable to read the uploaded CSV file.', [
            'received' => 0,
            'imported' => 0,
            'duplicates' => 0,
            'rejected' => 0,
            'errors' => [],
        ]);
    }

    $headers = fgetcsv($handle);
    if ($headers === false || $headers === [null]) {
        fclose($handle);
        import_response_error('The uploaded CSV file is empty or missing a header row.', [
            'received' => 0,
            'imported' => 0,
            'duplicates' => 0,
            'rejected' => 0,
            'errors' => [['row' => 1, 'field' => 'headers', 'reason' => 'Missing CSV header row']],
        ]);
    }

    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
    $rows = [];
    $rowNumber = 1;
    while (($values = fgetcsv($handle)) !== false) {
        $rowNumber++;
        if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) {
            continue;
        }
        $rows[] = ['row' => $rowNumber, 'values' => $values];
    }

    fclose($handle);
    return ['headers' => $headers, 'rows' => $rows];
}

function import_xlsx_rows(string $path): array
{
    if (!class_exists(ZipArchive::class)) {
        import_response_error('XLSX uploads are unavailable because PHP ZIP support is not enabled.', [
            'received' => 0,
            'imported' => 0,
            'duplicates' => 0,
            'rejected' => 0,
            'errors' => [],
        ], 500);
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        import_response_error('The uploaded XLSX file could not be opened.', [
            'received' => 0,
            'imported' => 0,
            'duplicates' => 0,
            'rejected' => 0,
            'errors' => [],
        ]);
    }

    $sharedStrings = [];
    $sharedStringXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedStringXml !== false) {
        $xml = simplexml_load_string($sharedStringXml, 'SimpleXMLElement', LIBXML_NONET);
        if ($xml !== false) {
            foreach ($xml->si as $item) {
                $sharedStrings[] = (string) $item->t;
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        $zip->close();
        import_response_error('The XLSX file does not contain a readable first worksheet.', [
            'received' => 0,
            'imported' => 0,
            'duplicates' => 0,
            'rejected' => 0,
            'errors' => [],
        ]);
    }

    $xml = simplexml_load_string($sheetXml, 'SimpleXMLElement', LIBXML_NONET);
    if ($xml === false) {
        $zip->close();
        import_response_error('The XLSX worksheet is malformed.', [
            'received' => 0,
            'imported' => 0,
            'duplicates' => 0,
            'rejected' => 0,
            'errors' => [],
        ]);
    }

    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $values = [];
        foreach ($row->c as $cell) {
            $reference = (string) $cell['r'];
            preg_match('/^[A-Z]+/', $reference, $columnName);
            $columnIndex = 0;
            foreach (str_split($columnName[0] ?? 'A') as $letter) {
                $columnIndex = $columnIndex * 26 + ord($letter) - ord('A') + 1;
            }

            $rawValue = (string) $cell->v;
            if ((string) $cell['t'] === 's' && isset($sharedStrings[(int) $rawValue])) {
                $rawValue = $sharedStrings[(int) $rawValue];
            } elseif ((string) $cell['t'] === 'inlineStr') {
                $rawValue = (string) $cell->is->t;
            }
            $values[$columnIndex - 1] = $rawValue;
        }

        if ($values !== []) {
            ksort($values);
            $rows[] = ['row' => (int) $row['r'], 'values' => $values];
        }
    }
    $zip->close();

    if ($rows === []) {
        import_response_error('No readable rows were found in the XLSX file.', [
            'received' => 0,
            'imported' => 0,
            'duplicates' => 0,
            'rejected' => 0,
            'errors' => [],
        ]);
    }

    $headers = array_shift($rows)['values'];
    return ['headers' => $headers, 'rows' => $rows];
}

function canonical_import_header(string $header): ?string
{
    $key = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $header)));
    $key = preg_replace('/[^a-z0-9]+/', '_', $key);
    $key = trim($key, '_');
    $aliases = [
        'ref_no' => ['ref_no', 'ref', 'reference', 'reference_no', 'reference_number', 'transaction_ref', 'transaction_reference', 'txn_ref'],
        'txn_date' => ['txn_date', 'date', 'transaction_date', 'value_date'],
        'amount' => ['amount', 'transaction_amount', 'total_amount'],
        'cross_ref' => ['cross_ref', 'cross_reference', 'bank_reference', 'deposit_reference', 'check_reference'],
        'support_ref' => ['support_ref', 'support_reference', 'source_reference'],
        'payee' => ['payee', 'beneficiary', 'recipient'],
        'description' => ['description', 'details', 'particulars'],
        'account_code' => ['account_code', 'gl_code'],
    ];

    foreach ($aliases as $canonical => $names) {
        if (in_array($key, $names, true)) {
            return $canonical;
        }
    }

    return null;
}

function parse_import_date(string $value): ?string
{
    $value = trim($value);
    foreach (['!Y-m-d', '!m/d/Y'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format($format === '!Y-m-d' ? 'Y-m-d' : 'm/d/Y') === $value
        ) {
            return $date->format('Y-m-d');
        }
    }

    return null;
}

function parse_import_amount(string $value): ?string
{
    $value = trim($value);
    if (!preg_match('/^-?(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d{1,2})?$/', $value)) {
        return null;
    }

    $amount = str_replace(',', '', $value);
    if (abs((float) $amount) >= 1000000000000) {
        return null;
    }

    return number_format((float) $amount, 2, '.', '');
}

$uploadError = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
if ($uploadError !== UPLOAD_ERR_OK) {
    $messages = [
        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the server upload limit.',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the form upload limit.',
        UPLOAD_ERR_PARTIAL => 'The file upload was incomplete.',
        UPLOAD_ERR_NO_FILE => 'Choose a CSV or XLSX file to upload.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server upload temporary directory is unavailable.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded file.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
    ];
    import_response_error($messages[$uploadError] ?? 'The file upload failed.', [
        'received' => 0,
        'imported' => 0,
        'duplicates' => 0,
        'rejected' => 0,
        'errors' => [],
    ]);
}

$source = ensure_allowed_source((string) ($_POST['source'] ?? ''));
$tmpPath = (string) ($_FILES['file']['tmp_name'] ?? '');
$fileName = basename((string) ($_FILES['file']['name'] ?? ''));
$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if (!in_array($extension, ['csv', 'xlsx'], true)) {
    import_response_error('Unsupported file type. Upload a CSV or XLSX file.', [
        'received' => 0,
        'imported' => 0,
        'duplicates' => 0,
        'rejected' => 0,
        'errors' => [],
    ]);
}
if ($fileName === '' || strlen($fileName) > 120) {
    import_response_error('The file name must be between 1 and 120 characters.', [
        'received' => 0,
        'imported' => 0,
        'duplicates' => 0,
        'rejected' => 0,
        'errors' => [],
    ]);
}
if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
    import_response_error('Uploaded file is invalid or unreadable.', [
        'received' => 0,
        'imported' => 0,
        'duplicates' => 0,
        'rejected' => 0,
        'errors' => [],
    ]);
}

$parsed = $extension === 'csv' ? import_csv_rows($tmpPath) : import_xlsx_rows($tmpPath);
$inputRows = $parsed['rows'];
$received = count($inputRows);
$errors = [];
$headerMap = [];
$seenHeaders = [];
$invalidHeaders = false;
foreach ($parsed['headers'] as $index => $header) {
    $canonical = canonical_import_header((string) $header);
    if ($canonical === null) {
        continue;
    }
    if (isset($seenHeaders[$canonical])) {
        $errors[] = ['row' => 1, 'field' => 'headers', 'reason' => 'Multiple columns map to ' . $canonical . '.'];
        $invalidHeaders = true;
        continue;
    }
    $seenHeaders[$canonical] = true;
    $headerMap[$index] = $canonical;
}

$requiredHeaders = ['ref_no', 'txn_date', 'amount'];
$missingHeaders = array_values(array_diff($requiredHeaders, array_keys($seenHeaders)));
if ($missingHeaders !== []) {
    $errors[] = [
        'row' => 1,
        'field' => 'headers',
        'reason' => 'Missing required columns: ' . implode(', ', $missingHeaders) . '.',
    ];
}

$providedPeriod = trim((string) ($_POST['period'] ?? ''));
if ($providedPeriod !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $providedPeriod)) {
    import_response_error('The selected reporting period must use YYYY-MM format.', [
        'received' => $received,
        'imported' => 0,
        'duplicates' => 0,
        'rejected' => $received,
        'errors' => [['row' => 0, 'field' => 'period', 'reason' => 'Invalid reporting period']],
    ]);
}

$validRows = [];
foreach ($inputRows as $inputRow) {
    $rowNumber = (int) $inputRow['row'];
    $values = $inputRow['values'];
    $normalized = [];
    foreach ($headerMap as $index => $field) {
        $normalized[$field] = trim((string) ($values[$index] ?? ''));
    }

    $rowErrors = [];
    if (count($values) > count($parsed['headers'])) {
        $rowErrors[] = ['row' => $rowNumber, 'field' => 'row', 'reason' => 'The row contains more values than the header.'];
    }

    $refNo = $normalized['ref_no'] ?? '';
    if ($refNo === '') {
        $rowErrors[] = ['row' => $rowNumber, 'field' => 'ref_no', 'reason' => 'Reference is required.'];
    } elseif (strlen($refNo) > 30) {
        $rowErrors[] = ['row' => $rowNumber, 'field' => 'ref_no', 'reason' => 'Reference must be 30 characters or fewer.'];
    }

    $txnDate = parse_import_date($normalized['txn_date'] ?? '');
    if ($txnDate === null) {
        $rowErrors[] = ['row' => $rowNumber, 'field' => 'txn_date', 'reason' => 'Use a valid YYYY-MM-DD or MM/DD/YYYY date.'];
    }

    $amount = parse_import_amount($normalized['amount'] ?? '');
    if ($amount === null) {
        $rowErrors[] = ['row' => $rowNumber, 'field' => 'amount', 'reason' => 'Use a numeric amount with up to two decimals and optional thousands separators.'];
    }

    $limits = ['cross_ref' => 30, 'support_ref' => 30, 'payee' => 120, 'description' => 160, 'account_code' => 12];
    foreach ($limits as $field => $limit) {
        if (strlen($normalized[$field] ?? '') > $limit) {
            $rowErrors[] = ['row' => $rowNumber, 'field' => $field, 'reason' => 'Value must be ' . $limit . ' characters or fewer.'];
        }
    }

    if ($txnDate !== null) {
        $rowPeriod = substr($txnDate, 0, 7);
        if ($providedPeriod !== '' && $providedPeriod !== $rowPeriod) {
            $rowErrors[] = ['row' => $rowNumber, 'field' => 'txn_date', 'reason' => 'Transaction date does not fall within selected period ' . $providedPeriod . '.'];
        } elseif ($providedPeriod === '' && isset($validRows[0]['period']) && $validRows[0]['period'] !== $rowPeriod) {
            $rowErrors[] = ['row' => $rowNumber, 'field' => 'txn_date', 'reason' => 'All transactions in one import must use the same reporting month.'];
        }
    }

    if ($rowErrors !== []) {
        array_push($errors, ...$rowErrors);
        continue;
    }

    $validRows[] = [
        'row' => $rowNumber,
        'period' => $providedPeriod !== '' ? $providedPeriod : substr($txnDate, 0, 7),
        'ref_no' => $refNo,
        'txn_date' => $txnDate,
        'amount' => $amount,
        'cross_ref' => $normalized['cross_ref'] ?? '',
        'support_ref' => $normalized['support_ref'] ?? '',
        'payee' => $normalized['payee'] ?? '',
        'description' => $normalized['description'] ?? '',
        'account_code' => $normalized['account_code'] ?? '',
    ];
}

$rejected = $received - count($validRows);
if ($missingHeaders !== [] || $invalidHeaders || count($validRows) === 0 && $received === 0) {
    import_response_error('The uploaded file contains no importable transaction rows.', [
        'received' => $received,
        'imported' => 0,
        'duplicates' => 0,
        'rejected' => $received,
        'errors' => $errors,
    ]);
}
if ($validRows === []) {
    import_response_error('No records staged: all transaction rows failed validation.', [
        'received' => $received,
        'imported' => 0,
        'duplicates' => 0,
        'rejected' => $rejected,
        'errors' => $errors,
    ]);
}

$period = $providedPeriod !== '' ? $providedPeriod : $validRows[0]['period'];
$pdo = db();
ensure_staging_tables($pdo);
$pdo->beginTransaction();

try {
    $createStage = $pdo->prepare(
        'INSERT INTO staged_imports
            (source, period, file_name, staged_by, staged_at, status, staged_count, duplicate_count, rejected_count)
         VALUES (:source, :period, :file_name, :user_id, NOW(), "staged", 0, 0, :rejected)'
    );
    $createStage->execute([
        ':source' => $source,
        ':period' => $period,
        ':file_name' => $fileName,
        ':user_id' => $_SESSION['user_id'] ?? null,
        ':rejected' => $rejected,
    ]);
    $stageId = (int) $pdo->lastInsertId();

    $existingRecord = $pdo->prepare('SELECT id FROM records WHERE ref_no = :ref_no LIMIT 1');
    $existingStage = $pdo->prepare(
        'SELECT sr.id
         FROM staged_records sr
         JOIN staged_imports si ON si.id = sr.staged_import_id
         WHERE sr.ref_no = :ref_no AND si.status = "staged"
         LIMIT 1'
    );
    $insert = $pdo->prepare(
        'INSERT INTO staged_records
            (staged_import_id, row_number, ref_no, cross_ref, support_ref, txn_date, period,
             amount, payee, description, account_code)
         VALUES
            (:stage_id, :row_number, :ref_no, :cross_ref, :support_ref, :txn_date, :period,
             :amount, :payee, :description, :account_code)'
    );
    $seenRefs = [];
    $staged = 0;
    $duplicates = 0;
    foreach ($validRows as $row) {
        if (isset($seenRefs[$row['ref_no']])) {
            $duplicates++;
            continue;
        }
        $seenRefs[$row['ref_no']] = true;

        $existingRecord->execute([':ref_no' => $row['ref_no']]);
        $existingStage->execute([':ref_no' => $row['ref_no']]);
        if ($existingRecord->fetchColumn() !== false || $existingStage->fetchColumn() !== false) {
            $duplicates++;
            continue;
        }

        $insert->execute([
            ':stage_id' => $stageId,
            ':row_number' => $row['row'],
            ':ref_no' => $row['ref_no'],
            ':cross_ref' => $row['cross_ref'] !== '' ? $row['cross_ref'] : null,
            ':support_ref' => $row['support_ref'] !== '' ? $row['support_ref'] : null,
            ':txn_date' => $row['txn_date'],
            ':period' => $row['period'],
            ':amount' => $row['amount'],
            ':payee' => $row['payee'] !== '' ? $row['payee'] : null,
            ':description' => $row['description'] !== '' ? $row['description'] : null,
            ':account_code' => $row['account_code'] !== '' ? $row['account_code'] : null,
        ]);
        $staged++;
    }

    $pdo->prepare('UPDATE staged_imports SET staged_count = :count, duplicate_count = :duplicates WHERE id = :id')->execute([
        ':count' => $staged,
        ':duplicates' => $duplicates,
        ':id' => $stageId,
    ]);

    if ($staged > 0) {
        log_audit_event('Records staged', 'Consolidation', $fileName, 'success', $staged . ' rows staged; ' . $duplicates . ' duplicates skipped; ' . $rejected . ' rows rejected');
    } else {
        log_audit_event('Duplicate import skipped', 'Consolidation', $fileName, 'flagged', $duplicates . ' duplicate rows skipped while staging');
    }
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

json_success([
    'received' => $received,
    'staged' => $staged,
    'stage_id' => $stageId,
    'duplicates' => $duplicates,
    'rejected' => $rejected,
    'errors' => $errors,
]);
