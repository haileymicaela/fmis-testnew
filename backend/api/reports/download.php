<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/auth.php';

require_once __DIR__ . '/../../includes/report_data.php';

function render_xlsx_download(string $title, string $period, string $target): void
{
    $dir = dirname($target);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    if (file_exists($target)) {
        return;
    }

    $docs = [
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>',
        'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . htmlspecialchars($title, ENT_XML1) . '</dc:title><dc:creator>CSPC Accounting Module</dc:creator><cp:lastModifiedBy>CSPC Accounting Module</cp:lastModifiedBy></cp:coreProperties>',
        'docProps/app.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>CSPC Accounting Module</Application></Properties>',
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="CSPC Report" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>Report</t></is></c><c r="B1" t="inlineStr"><is><t>' . htmlspecialchars($title, ENT_XML1) . '</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>Period</t></is></c><c r="B2" t="inlineStr"><is><t>' . htmlspecialchars($period, ENT_XML1) . '</t></is></c></row></sheetData></worksheet>',
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
}

require_method('GET');
require_auth();

$id = (int) ($_GET['id'] ?? 0);
$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM reports WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$report = $stmt->fetch();
if (!$report) {
    json_error('Report not found.', 404);
}

$baseDir = __DIR__ . '/../../storage/reports';
$baseDirExists = is_dir($baseDir) || mkdir($baseDir, 0775, true);
if (!$baseDirExists) {
    json_error('The report storage directory is unavailable.', 500);
}
$filename = $report['report_code'] . '.' . $report['format'];
$fullPath = $baseDir . '/' . $filename;
if ($report['format'] === 'pdf') {
    // Rebuild PDFs so previously cached files with a malformed xref table are repaired.
    $rows = build_report_rows($pdo, $report['report_type'], $report['period']);
    $pdfTitle = $report['report_type'] . ' - ' . $report['period'];
    $written = file_put_contents($fullPath, render_report_pdf($pdfTitle, $rows));
    if ($written === false) {
        json_error('The PDF report could not be written.', 500);
    }
} elseif (!file_exists($fullPath)) {
    render_xlsx_download($report['report_type'], $report['period'], $fullPath);
}

if (!file_exists($fullPath)) {
    json_error('The report file could not be generated.', 500);
}

header('Content-Type: ' . ($report['format'] === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
header('Content-Disposition: attachment; filename="' . basename($fullPath) . '"');
header('Content-Length: ' . filesize($fullPath));
readfile($fullPath);
exit;
