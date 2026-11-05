<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

require_method('POST');
require_auth();
check_csrf_if_present();

require_once __DIR__ . '/../../includes/report_data.php';

function render_xlsx_report(string $title, string $period, string $target, array $rows = []): void
{
    $dir = dirname($target);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    if (file_exists($target)) {
        @unlink($target);
    }

    $sheetRows = [['Report', $title], ['Period', $period], ...$rows];
    $sheetData = '';
    foreach ($sheetRows as $rowIndex => $row) {
        if ($row === []) {
            continue;
        }
        $rowNumber = $rowIndex + 1;
        $sheetData .= '<row r="' . $rowNumber . '">';
        foreach (array_values($row) as $columnIndex => $value) {
            $column = '';
            for ($n = $columnIndex + 1; $n > 0; $n = intdiv($n - 1, 26)) {
                $column = chr(65 + (($n - 1) % 26)) . $column;
            }
            $cell = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $sheetData .= '<c r="' . $column . $rowNumber . '" t="inlineStr"><is><t>'
                . $cell . '</t></is></c>';
        }
        $sheetData .= '</row>';
    }
    $worksheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $sheetData . '</sheetData></worksheet>';

    $docs = [
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>',
        'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . htmlspecialchars($title, ENT_XML1) . '</dc:title><dc:creator>CSPC Accounting Module</dc:creator><cp:lastModifiedBy>CSPC Accounting Module</cp:lastModifiedBy></cp:coreProperties>',
        'docProps/app.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>CSPC Accounting Module</Application></Properties>',
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="CSPC Report" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml' => $worksheet,
    ];

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            foreach ($docs as $name => $content) {
                $zip->addFromString($name, $content);
            }
            $zip->close();
            return;
        }
    }

    $json = json_encode($docs, JSON_UNESCAPED_SLASHES);
    $script = tempnam(sys_get_temp_dir(), 'xlsx_');
    $jsonFile = tempnam(sys_get_temp_dir(), 'xlsx_json_');
    file_put_contents($script, "import json, sys, zipfile\nfrom pathlib import Path\n\ntarget = Path(sys.argv[1])\njson_path = Path(sys.argv[2])\nfiles = json.loads(json_path.read_text(encoding='utf-8'))\ntarget.parent.mkdir(parents=True, exist_ok=True)\nwith zipfile.ZipFile(target, 'w', compression=zipfile.ZIP_DEFLATED) as z:\n    for name, content in files.items():\n        z.writestr(name, content)\n");
    file_put_contents($jsonFile, $json);
    exec('python ' . escapeshellarg($script) . ' ' . escapeshellarg($target) . ' ' . escapeshellarg($jsonFile), $out, $code);
    @unlink($script);
    @unlink($jsonFile);
    if ($code !== 0 || !file_exists($target) || filesize($target) === 0) {
        throw new RuntimeException('Excel report generation failed: ' . implode("\n", $out));
    }
}

$payload = read_json_input();
$type = strtoupper(trim((string) ($payload['type'] ?? $_POST['type'] ?? 'reconciliation_summary')));
$format = strtolower(trim((string) ($payload['format'] ?? $_POST['format'] ?? 'pdf')));
$period = get_period_value((string) ($payload['period'] ?? $_POST['period'] ?? ''));

if ($format !== 'pdf' && $format !== 'excel') {
    json_error('The selected report format is not supported.', 422);
}

$pdo = db();
$userName = $_SESSION['user_name'] ?? 'System';
$reportCode = 'RPT-' . date('dHis');
$generatedAt = date('Y-m-d H:i:s');
$reportTypeMap = [
    'RECONCILIATION_SUMMARY' => 'reconciliation_summary',
    'DISCREPANCY_REPORT' => 'discrepancy_report',
    'BANK_LEDGER_VARIANCE' => 'bank_ledger_variance',
    'FLAGGED_AGING' => 'flagged_aging',
];
$reportType = $reportTypeMap[$type] ?? 'reconciliation_summary';
$reportRows = build_report_rows($pdo, $reportType, $period);

$insert = $pdo->prepare(
    'INSERT INTO reports (report_code, report_type, period, generated_by, generated_at, format)
     VALUES (:report_code, :report_type, :period, :generated_by, :generated_at, :format)'
);
$insert->execute([
    ':report_code' => $reportCode,
    ':report_type' => $reportType,
    ':period' => $period,
    ':generated_by' => $_SESSION['user_id'] ?? null,
    ':generated_at' => $generatedAt,
    ':format' => $format,
]);

$reportId = (int) $pdo->lastInsertId();
$baseDir = __DIR__ . '/../../storage/reports';
if (!is_dir($baseDir)) {
    mkdir($baseDir, 0777, true);
}

$filename = $reportCode . '.' . $format;
$fullPath = $baseDir . '/' . $filename;

if ($format === 'pdf') {
    file_put_contents($fullPath, render_report_pdf($reportType . ' · ' . $period, $reportRows));
} else {
    render_xlsx_report($reportType, $period, $fullPath, $reportRows);
}

log_audit_event('Report generated', 'Reports', $reportCode, 'success', $type . ' report created');

json_success([
    'id' => $reportId,
    'report_code' => $reportCode,
    'report_type' => $reportType,
    'period' => $period,
    'generated_by' => $userName,
    'generated_at' => $generatedAt,
    'format' => $format,
    'download_url' => '/backend/api/reports/download.php?id=' . $reportId,
]);
