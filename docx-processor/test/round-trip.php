<?php

declare(strict_types=1);

use ArtifactFlow\DocxProcessor\DocxPackageInspector;
use ArtifactFlow\DocxProcessor\DocxConversionSanitizer;
use ArtifactFlow\DocxProcessor\LibreOfficeConverter;

require dirname(__DIR__) . '/src/DocxProcessor.php';

$path = tempnam('/tmp', 'artifactflow-docx-round-trip-');
if (!is_string($path)) {
    throw new RuntimeException('Could not allocate the DOCX round-trip fixture.');
}

$archive = new ZipArchive();
if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Could not create the DOCX round-trip fixture.');
}

$emf = pack('V*',
    1,
    88,
    0,
    0,
    100,
    100,
    0,
    0,
    2_540,
    2_540,
    0x464D4520,
    0x00010000,
    132,
    3,
    1,
    0,
    0,
    0,
    96,
    96,
    25,
    25,
) . pack('V*', 43, 24, 10, 10, 90, 90) . pack('V*', 14, 20, 0, 16, 20);

$entries = [
    '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="emf" ContentType="image/x-emf"/><Default Extension="odttf" ContentType="application/vnd.openxmlformats-officedocument.obfuscatedFont"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/fontTable.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.fontTable+xml"/><Override PartName="/word/charts/chart1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/><Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/><Override PartName="/word/printerSettings/printerSettings1.bin" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.printerSettings"/><Override PartName="/customXml/itemProps1.xml" ContentType="application/vnd.openxmlformats-officedocument.customXmlProperties+xml"/></Types>',
    '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
    'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:v="urn:schemas-microsoft-com:vml"><w:body><w:p><w:r><w:t>ArtifactFlow searchable Word preview</w:t></w:r></w:p><w:sdt><w:sdtPr><w:dataBinding w:prefixMappings="" w:xpath="/root[1]/value[1]" w:storeItemID="{00112233-4455-6677-8899-AABBCCDDEEFF}"/></w:sdtPr><w:sdtContent><w:p><w:r><w:t>Cached custom XML preview text</w:t></w:r></w:p></w:sdtContent></w:sdt><w:p><w:r><w:pict><v:shape id="ArtifactFlowEmf" style="width:72pt;height:72pt"><v:imagedata r:id="rId1"/></v:shape></w:pict></w:r></w:p><w:sectPr/></w:body></w:document>',
    'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/vector.emf"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/fontTable" Target="fontTable.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXml" Target="../customXml/item1.xml"/><Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="charts/chart1.xml"/><Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/></Relationships>',
    'word/media/vector.emf' => $emf,
    'word/fontTable.xml' => '<?xml version="1.0" encoding="UTF-8"?><w:fonts xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:font w:name="ArtifactFlow Embedded"><w:embedRegular r:id="rId1" w:fontKey="{00112233-4455-6677-8899-AABBCCDDEEFF}"/></w:font></w:fonts>',
    'word/_rels/fontTable.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/font" Target="fonts/font1.odttf"/></Relationships>',
    'word/fonts/font1.odttf' => "untrusted-obfuscated-font-bytes\x00\x01",
    'word/charts/chart1.xml' => '<?xml version="1.0" encoding="UTF-8"?><c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"><c:chart/></c:chartSpace>',
    'word/settings.xml' => '<?xml version="1.0" encoding="UTF-8"?><w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>',
    'word/_rels/settings.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/printerSettings" Target="printerSettings/printerSettings1.bin"/></Relationships>',
    'word/printerSettings/printerSettings1.bin' => "bounded-printer-settings\x00",
    'customXml/item1.xml' => '<?xml version="1.0" encoding="UTF-8"?><root><value>Mapped custom XML value</value></root>',
    'customXml/_rels/item1.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXmlProps" Target="itemProps1.xml"/></Relationships>',
    'customXml/itemProps1.xml' => '<?xml version="1.0" encoding="UTF-8"?><ds:datastoreItem ds:itemID="{00112233-4455-6677-8899-AABBCCDDEEFF}" xmlns:ds="http://schemas.openxmlformats.org/officeDocument/2006/customXml"><ds:schemaRefs/></ds:datastoreItem>',
];

foreach ($entries as $name => $bytes) {
    if (!$archive->addFromString($name, $bytes)) {
        throw new RuntimeException('Could not add a DOCX round-trip fixture part.');
    }
}
$archive->close();

try {
    $docx = file_get_contents($path);
    if (!is_string($docx)) {
        throw new RuntimeException('Could not read the DOCX round-trip fixture.');
    }

    $docxOutputPath = $argv[2] ?? null;
    if (is_string($docxOutputPath) && file_put_contents($docxOutputPath, $docx, LOCK_EX) !== strlen($docx)) {
        throw new RuntimeException('Could not retain the DOCX round-trip fixture.');
    }

    $inspector = new DocxPackageInspector();
    $inspector->inspect($docx);
    $conversionCopy = (new DocxConversionSanitizer())->stripForConversion($docx);
    $inspector->inspect($conversionCopy);
    $converter = new LibreOfficeConverter();
    $converter->verifyHealth();
    $result = $converter->convert($conversionCopy);

    if (!str_starts_with($result->pdfBytes, '%PDF-') || !str_contains($result->pdfBytes, '%%EOF')) {
        throw new RuntimeException('DOCX round-trip did not produce a complete PDF.');
    }

    $outputPath = $argv[1] ?? null;
    if (is_string($outputPath) && file_put_contents($outputPath, $result->pdfBytes, LOCK_EX) !== strlen($result->pdfBytes)) {
        throw new RuntimeException('Could not retain the DOCX-derived PDF for downstream verification.');
    }

    echo hash('sha256', $docx) . ' ' . strlen($result->pdfBytes) . ' ' . hash('sha256', $result->pdfBytes) . "\n";
} finally {
    unlink($path);
}
