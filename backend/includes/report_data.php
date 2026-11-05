<?php
declare(strict_types=1);

function render_report_pdf(string $title, array $rows): string
{
    $escape = static fn(string $text): string => str_replace(
        ["\\", "(", ")", "\r", "\n"],
        ["\\\\", "\\(", "\\)", '', ' '],
        $text
    );
    $stream = "BT /F1 14 Tf 50 750 Td (" . $escape($title) . ") Tj ET\n";
    foreach (array_slice($rows, 0, 35) as $index => $row) {
        $line = implode(' | ', array_map(static fn($value): string => (string) $value, $row));
        $stream .= "BT /F1 8 Tf 35 " . (720 - $index * 19) . " Td (" . $escape($line) . ") Tj ET\n";
    }

    $objects = [
        "<< /Type /Catalog /Pages 2 0 R >>",
        "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>",
        "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream",
        "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xrefStart = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefStart . "\n%%EOF";

    return $pdf;
}

function build_report_rows(PDO $pdo, string $reportType, string $period): array
{
    if ($reportType === 'discrepancy_report') {
        $stmt = $pdo->prepare(
            'SELECT d.ref_code, r.ref_no, d.issue_type, d.status, d.flagged_on, d.variance, d.diagnosis
             FROM discrepancies d
             JOIN records r ON r.id = d.record_id
             WHERE d.period = :period
             ORDER BY d.flagged_on DESC, d.id DESC'
        );
        $stmt->execute([':period' => $period]);
        $rows = [['Reference', 'Record', 'Issue', 'Status', 'Flagged on', 'Variance', 'Diagnosis']];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = array_values($row);
        }
        return $rows;
    }

    if ($reportType === 'bank_ledger_variance') {
        $stmt = $pdo->prepare(
            'SELECT b.ref_no AS bank_reference, l.ref_no AS ledger_reference, m.amount,
                    b.amount AS bank_amount, l.amount AS ledger_amount, m.date_diff, m.result
             FROM matches m
             LEFT JOIN records b ON b.id = m.bank_id
             LEFT JOIN records l ON l.id = m.ledger_id
             WHERE m.period = :period
             ORDER BY m.id'
        );
        $stmt->execute([':period' => $period]);
        $rows = [['Bank reference', 'Ledger reference', 'Match amount', 'Bank amount', 'Ledger amount', 'Date difference', 'Result']];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = array_values($row);
        }
        return $rows;
    }

    if ($reportType === 'flagged_aging') {
        $stmt = $pdo->prepare(
            'SELECT d.ref_code, r.ref_no, d.issue_type, d.status, d.flagged_at,
                    DATEDIFF(:as_of_date, DATE(d.flagged_at)) AS days_open,
                    d.assigned_to
             FROM discrepancies d
             JOIN records r ON r.id = d.record_id
             WHERE d.period = :period
             ORDER BY days_open DESC, d.id DESC'
        );
        $stmt->execute([':period' => $period, ':as_of_date' => app_today()]);
        $rows = [['Reference', 'Record', 'Issue', 'Status', 'Created at', 'Days open', 'Assigned user ID']];
        foreach ($stmt->fetchAll() as $row) {
            $rows[] = array_values($row);
        }
        return $rows;
    }

    $summary = $pdo->prepare(
        'SELECT source,
                SUM(rec_status = "matched") AS matched,
                SUM(rec_status = "possible") AS possible,
                SUM(rec_status = "unmatched") AS unmatched,
                SUM(rec_status = "duplicate") AS duplicate,
                SUM(rec_status = "pending") AS pending,
                COUNT(*) AS total
         FROM records
         WHERE period = :period
         GROUP BY source
         ORDER BY FIELD(source, "bank", "ledger", "sias", "voucher")'
    );
    $summary->execute([':period' => $period]);
    $rows = [['Source', 'Matched', 'Possible', 'Unmatched', 'Duplicate', 'Pending', 'Total']];
    foreach ($summary->fetchAll() as $row) {
        $rows[] = array_values($row);
    }

    $records = $pdo->prepare(
        'SELECT ref_no, source, txn_date, amount, rec_status, payee, description
         FROM records WHERE period = :period
         ORDER BY txn_date, id'
    );
    $records->execute([':period' => $period]);
    $rows[] = [];
    $rows[] = ['Reference', 'Source', 'Date', 'Amount', 'Status', 'Payee', 'Description'];
    foreach ($records->fetchAll() as $row) {
        $rows[] = array_values($row);
    }
    return $rows;
}

