<?php

declare(strict_types=1);

use ArtifactFlow\DocxProcessor\DocxPackageInspector;
use ArtifactFlow\DocxProcessor\DocxConversionSanitizer;
use ArtifactFlow\DocxProcessor\ProcessorAuthenticationFailure;
use ArtifactFlow\DocxProcessor\ProcessorConfiguration;
use ArtifactFlow\DocxProcessor\ProcessorHealthRequest;
use ArtifactFlow\DocxProcessor\ProcessorRejection;
use ArtifactFlow\DocxProcessor\ProcessorRequest;
use ArtifactFlow\DocxProcessor\ProcessorUnavailable;
require dirname(__DIR__) . '/src/DocxProcessor.php';

/**
 * @param array<string, string> $entries
 * @param null|callable(ZipArchive): void $mutate
 */
function package(array $entries, ?callable $mutate = null): string
{
    $path = tempnam('/tmp', 'artifactflow-docx-test-');
    if (!is_string($path)) {
        throw new RuntimeException('Could not allocate test package.');
    }

    $archive = new ZipArchive();
    if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create test package.');
    }
    foreach ($entries as $name => $bytes) {
        $archive->addFromString($name, $bytes);
    }
    if ($mutate !== null) {
        $mutate($archive);
    }
    $archive->close();
    $bytes = file_get_contents($path);
    unlink($path);

    if (!is_string($bytes)) {
        throw new RuntimeException('Could not read test package.');
    }

    return $bytes;
}

/** @return array<string, string> */
function packageEntries(string $bytes): array
{
    $path = tempnam('/tmp', 'artifactflow-docx-read-test-');
    if (!is_string($path)) {
        throw new RuntimeException('Could not allocate package inspection storage.');
    }

    try {
        if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new RuntimeException('Could not stage package inspection storage.');
        }

        $archive = new ZipArchive();
        if ($archive->open($path, ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('Could not open package inspection storage.');
        }

        try {
            $entries = [];
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $stat = $archive->statIndex($index, ZipArchive::FL_UNCHANGED);
                if (!is_array($stat) || !is_string($stat['name'] ?? null)) {
                    throw new RuntimeException('Could not inspect a package entry.');
                }
                $entry = $archive->getFromIndex($index, 64 * 1024 * 1024 + 1, ZipArchive::FL_UNCHANGED);
                if (!is_string($entry)) {
                    throw new RuntimeException('Could not read a package entry.');
                }
                $entries[$stat['name']] = $entry;
            }

            return $entries;
        } finally {
            $archive->close();
        }
    } finally {
        unlink($path);
    }
}

/** @return array<string, string> */
function baseEntries(string $document = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Hello DOCX</w:t></w:r></w:p></w:body></w:document>'): array
{
    return [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
        'word/document.xml' => $document,
    ];
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectRejected(string $bytes, string $message): void
{
    try {
        (new DocxPackageInspector())->inspect($bytes);
    } catch (ProcessorRejection) {
        return;
    }

    throw new RuntimeException($message);
}

function expectRejectedWithMessage(string $bytes, string $expectedMessage, string $message): void
{
    try {
        (new DocxPackageInspector())->inspect($bytes);
    } catch (ProcessorRejection $exception) {
        assertTrue($exception->getMessage() === $expectedMessage, $message);

        return;
    }

    throw new RuntimeException($message);
}

$diagnosticRejection = new ProcessorRejection('private document detail must not reach logs');
assertTrue(
    preg_match('/\A[a-f0-9]{12}\z/', $diagnosticRejection->diagnosticCode()) === 1,
    'DOCX rejection diagnostics must be a bounded opaque code.',
);
assertTrue(
    !str_contains($diagnosticRejection->diagnosticCode(), 'private'),
    'DOCX rejection diagnostics must not expose exception text.',
);
$contextualRejection = ProcessorRejection::withDiagnosticContext(
    'The DOCX package contains an unsupported Word part.',
    'word/private-document-detail.xml',
);
assertTrue(
    preg_match('/\A[a-f0-9]{12}\z/', $contextualRejection->diagnosticContextCode() ?? '') === 1,
    'DOCX rejection context diagnostics must be a bounded opaque code.',
);
assertTrue(
    !str_contains($contextualRejection->diagnosticContextCode() ?? '', 'private'),
    'DOCX rejection context diagnostics must not expose package part names.',
);
$diagnosticUnavailable = new ProcessorUnavailable('private converter detail must not reach logs');
assertTrue(
    preg_match('/\A[a-f0-9]{12}\z/', $diagnosticUnavailable->diagnosticCode()) === 1,
    'DOCX unavailable diagnostics must be a bounded opaque code.',
);
assertTrue(
    !str_contains($diagnosticUnavailable->diagnosticCode(), 'private'),
    'DOCX unavailable diagnostics must not expose exception text.',
);

/** @return non-empty-string */
function testPng(): string
{
    $bytes = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAIAAAB7QOjdAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAD0lEQVQImWNUSHhwQcIBAAkOAopyJglZAAAAAElFTkSuQmCC',
        true,
    );
    assertTrue(is_string($bytes) && $bytes !== '', 'PNG fixture could not be decoded.');

    return $bytes;
}

/** @return non-empty-string */
function testPngWithDimensions(int $width, int $height): string
{
    $bytes = substr_replace(testPng(), pack('N', $width), 16, 4);
    $bytes = substr_replace($bytes, pack('N', $height), 20, 4);

    assertTrue($bytes !== '', 'Dimensioned PNG fixture could not be created.');

    return $bytes;
}

/** @return non-empty-string */
function testJpeg(): string
{
    $bytes = base64_decode(
        '/9j/4AAQSkZJRgABAQAASABIAAD/4QBMRXhpZgAATU0AKgAAAAgAAYdpAAQAAAABAAAAGgAAAAAAA6ABAAMAAAABAAEAAKACAAQAAAABAAAAAaADAAQAAAABAAAAAQAAAAD/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9sAQwACAgICAgIDAgIDBQMDAwUGBQUFBQYIBgYGBgYICggICAgICAoKCgoKCgoKDAwMDAwMDg4ODg4PDw8PDw8PDw8P/9sAQwECAgIEBAQHBAQHEAsJCxAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQ/90ABAAB/9oADAMBAAIRAxEAPwD8Y6KKK9AzP//Z',
        true,
    );
    assertTrue(is_string($bytes) && $bytes !== '', 'JPEG fixture could not be decoded.');

    return $bytes;
}

/** @return non-empty-string */
function testEmf(): string
{
    $header = pack('V*',
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
    );
    $rectangle = pack('V*', 43, 24, 10, 10, 90, 90);
    $eof = pack('V*', 14, 20, 0, 16, 20);
    $bytes = $header . $rectangle . $eof;

    assertTrue(strlen($bytes) === 132, 'EMF fixture has an invalid size.');

    return $bytes;
}

/** @return array<string, string> */
function embeddedFontEntries(): array
{
    $entries = baseEntries();
    $entries['word/fontTable.xml'] = '<?xml version="1.0" encoding="UTF-8"?><w:fonts xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:font w:name="ArtifactFlow Embedded"><w:embedRegular r:id="rId1" w:fontKey="{00112233-4455-6677-8899-AABBCCDDEEFF}"/></w:font></w:fonts>';
    $entries['word/_rels/document.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/fontTable" Target="fontTable.xml"/></Relationships>';
    $entries['word/_rels/fontTable.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/font" Target="fonts/font1.odttf"/></Relationships>';
    $entries['word/fonts/font1.odttf'] = "untrusted-obfuscated-font-bytes\x00\x01";
    $entries['[Content_Types].xml'] = str_replace(
        '</Types>',
        '<Default Extension="odttf" ContentType="application/vnd.openxmlformats-officedocument.obfuscatedFont"/><Override PartName="/word/fontTable.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.fontTable+xml"/></Types>',
        $entries['[Content_Types].xml'],
    );

    return $entries;
}

/** @return array<string, string> */
function customXmlEntries(): array
{
    $entries = baseEntries(
        '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:sdt><w:sdtPr><w:dataBinding w:prefixMappings="" w:xpath="/root[1]/value[1]" w:storeItemID="{00112233-4455-6677-8899-AABBCCDDEEFF}"/></w:sdtPr><w:sdtContent><w:p><w:r><w:t>Cached visible custom XML text</w:t></w:r></w:p></w:sdtContent></w:sdt></w:body></w:document>',
    );
    $entries['word/_rels/document.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXml" Target="../customXml/item1.xml"/></Relationships>';
    $entries['customXml/item1.xml'] = '<?xml version="1.0" encoding="UTF-8"?><root><value>Mapped custom XML value</value></root>';
    $entries['customXml/_rels/item1.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXmlProps" Target="itemProps1.xml"/></Relationships>';
    $entries['customXml/itemProps1.xml'] = '<?xml version="1.0" encoding="UTF-8"?><ds:datastoreItem ds:itemID="{00112233-4455-6677-8899-AABBCCDDEEFF}" xmlns:ds="http://schemas.openxmlformats.org/officeDocument/2006/customXml"><ds:schemaRefs/></ds:datastoreItem>';
    $entries['[Content_Types].xml'] = str_replace(
        '</Types>',
        '<Override PartName="/customXml/itemProps1.xml" ContentType="application/vnd.openxmlformats-officedocument.customXmlProperties+xml"/></Types>',
        $entries['[Content_Types].xml'],
    );

    return $entries;
}

/** @return array<string, string> */
function attachedTemplateEntries(): array
{
    $entries = baseEntries();
    $entries['word/settings.xml'] = '<?xml version="1.0" encoding="UTF-8"?><w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:attachedTemplate r:id="rId1"/></w:settings>';
    $entries['word/_rels/document.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/></Relationships>';
    $entries['word/_rels/settings.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/attachedTemplate" Target="file:///Users/example/private/Normal.dotm" TargetMode="External"/></Relationships>';
    $entries['[Content_Types].xml'] = str_replace(
        '</Types>',
        '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/></Types>',
        $entries['[Content_Types].xml'],
    );

    return $entries;
}

$benign = package(baseEntries());
$facts = (new DocxPackageInspector())->inspect($benign);
assertTrue($facts->entryCount === 3, 'Benign DOCX entry count was not retained.');
assertTrue($facts->relationshipCount === 1, 'Benign DOCX relationships were not counted.');

$customXml = package(customXmlEntries());
$customXmlFacts = (new DocxPackageInspector())->inspect($customXml);
assertTrue($customXmlFacts->relationshipCount === 3, 'A bounded custom-XML DOCX was not accepted.');
$customXmlStripped = (new DocxConversionSanitizer())->stripForConversion($customXml);
(new DocxPackageInspector())->inspect($customXmlStripped);
$customXmlStrippedEntries = packageEntries($customXmlStripped);
assertTrue(
    array_filter(
        array_keys($customXmlStrippedEntries),
        static fn (string $name): bool => str_starts_with($name, 'customXml/'),
    ) === [],
    'Custom XML parts reached the conversion copy.',
);
assertTrue(
    !str_contains($customXmlStrippedEntries['word/document.xml'] ?? '', 'dataBinding'),
    'The conversion copy retained a custom XML data binding.',
);
assertTrue(
    str_contains($customXmlStrippedEntries['word/document.xml'] ?? '', 'Cached visible custom XML text'),
    'The conversion copy lost cached visible content while removing its data binding.',
);

$attachedTemplate = package(attachedTemplateEntries());
$attachedTemplateFacts = (new DocxPackageInspector())->inspect($attachedTemplate);
assertTrue(
    $attachedTemplateFacts->relationshipCount === 3,
    'A standard settings-owned attached-template relationship was not accepted.',
);
$attachedTemplateStripped = (new DocxConversionSanitizer())->stripForConversion($attachedTemplate);
assertTrue(
    $attachedTemplateStripped !== $attachedTemplate,
    'An attached-template DOCX was not rewritten for conversion.',
);
(new DocxPackageInspector())->inspect($attachedTemplateStripped);
$attachedTemplateStrippedEntries = packageEntries($attachedTemplateStripped);
assertTrue(
    !str_contains($attachedTemplateStrippedEntries['word/settings.xml'] ?? '', 'attachedTemplate'),
    'The conversion copy retained the attached-template WordprocessingML reference.',
);
assertTrue(
    !str_contains($attachedTemplateStrippedEntries['word/_rels/settings.xml.rels'] ?? '', 'attachedTemplate'),
    'The conversion copy retained the attached-template relationship.',
);
assertTrue(
    !str_contains($attachedTemplateStripped, 'file:///Users/example/private/Normal.dotm'),
    'The conversion copy retained the external template target.',
);

$wrongOwnerAttachedTemplate = attachedTemplateEntries();
$wrongOwnerAttachedTemplate['word/_rels/settings.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>';
$wrongOwnerAttachedTemplate['word/_rels/document.xml.rels'] = str_replace(
    '</Relationships>',
    '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/attachedTemplate" Target="file:///Users/example/private/Normal.dotm" TargetMode="External"/></Relationships>',
    $wrongOwnerAttachedTemplate['word/_rels/document.xml.rels'],
);
expectRejected(
    package($wrongOwnerAttachedTemplate),
    'An attached-template relationship outside word/settings.xml was accepted.',
);

$internalAttachedTemplate = attachedTemplateEntries();
$internalAttachedTemplate['word/_rels/settings.xml.rels'] = str_replace(
    'Target="file:///Users/example/private/Normal.dotm" TargetMode="External"',
    'Target="../document.xml"',
    $internalAttachedTemplate['word/_rels/settings.xml.rels'],
);
expectRejected(
    package($internalAttachedTemplate),
    'An internal attached-template relationship was accepted.',
);

$externalCustomXml = customXmlEntries();
$externalCustomXml['word/_rels/document.xml.rels'] = str_replace(
    'Target="../customXml/item1.xml"',
    'Target="https://example.com/item1.xml" TargetMode="External"',
    $externalCustomXml['word/_rels/document.xml.rels'],
);
expectRejected(package($externalCustomXml), 'An externally hosted custom XML part was accepted.');

$misboundCustomXml = customXmlEntries();
$misboundCustomXml['customXml/_rels/item1.xml.rels'] = str_replace(
    'Target="itemProps1.xml"',
    'Target="itemProps2.xml"',
    $misboundCustomXml['customXml/_rels/item1.xml.rels'],
);
$misboundCustomXml['customXml/itemProps2.xml'] = $misboundCustomXml['customXml/itemProps1.xml'];
unset($misboundCustomXml['customXml/itemProps1.xml']);
$misboundCustomXml['[Content_Types].xml'] = str_replace(
    '/customXml/itemProps1.xml',
    '/customXml/itemProps2.xml',
    $misboundCustomXml['[Content_Types].xml'],
);
expectRejected(package($misboundCustomXml), 'A custom XML item linked to mismatched properties was accepted.');

$escapingCustomXml = customXmlEntries();
$escapingCustomXml['word/_rels/document.xml.rels'] = str_replace(
    'Target="../customXml/item1.xml"',
    'Target="../../customXml/item1.xml"',
    $escapingCustomXml['word/_rels/document.xml.rels'],
);
expectRejected(package($escapingCustomXml), 'A custom XML relationship escaped the package root.');

$tooManyCustomXmlParts = customXmlEntries();
for ($customXmlIndex = 2; $customXmlIndex <= 43; $customXmlIndex++) {
    $tooManyCustomXmlParts['customXml/item' . $customXmlIndex . '.xml'] = '<root/>';
    $tooManyCustomXmlParts['customXml/_rels/item' . $customXmlIndex . '.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXmlProps" Target="itemProps' . $customXmlIndex . '.xml"/></Relationships>';
    $tooManyCustomXmlParts['customXml/itemProps' . $customXmlIndex . '.xml'] = '<ds:datastoreItem xmlns:ds="http://schemas.openxmlformats.org/officeDocument/2006/customXml"/>';
}
expectRejectedWithMessage(
    package($tooManyCustomXmlParts),
    'The DOCX package exceeds its custom-XML-part-count limit.',
    'The custom-XML-part-count limit did not produce its dedicated rejection.',
);

$passiveOptionalParts = baseEntries();
$passiveOptionalParts['word/charts/chart1.xml'] = '<?xml version="1.0" encoding="UTF-8"?><c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"><c:chart/></c:chartSpace>';
$passiveOptionalParts['word/diagrams/data1.xml'] = '<?xml version="1.0" encoding="UTF-8"?><dgm:dataModel xmlns:dgm="http://schemas.openxmlformats.org/drawingml/2006/diagram"><dgm:ptLst/><dgm:cxnLst/></dgm:dataModel>';
$passiveOptionalParts['word/commentsExtensible.xml'] = '<?xml version="1.0" encoding="UTF-8"?><w16cex:commentsExtensible xmlns:w16cex="http://schemas.microsoft.com/office/word/2018/wordml/cex"/>';
$passiveOptionalParts['word/settings.xml'] = '<?xml version="1.0" encoding="UTF-8"?><w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>';
$passiveOptionalParts['word/printerSettings/printerSettings1.bin'] = "bounded-printer-settings\x00";
$passiveOptionalParts['docProps/thumbnail.emf'] = testEmf();
$passiveOptionalParts['_rels/.rels'] = str_replace(
    '</Relationships>',
    '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/thumbnail" Target="docProps/thumbnail.emf"/></Relationships>',
    $passiveOptionalParts['_rels/.rels'],
);
$passiveOptionalParts['word/_rels/document.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="charts/chart1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/diagramData" Target="diagrams/data1.xml"/><Relationship Id="rId3" Type="http://schemas.microsoft.com/office/2018/08/relationships/commentsExtensible" Target="commentsExtensible.xml"/><Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/></Relationships>';
$passiveOptionalParts['word/_rels/settings.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/printerSettings" Target="printerSettings/printerSettings1.bin"/></Relationships>';
$passiveOptionalParts['[Content_Types].xml'] = str_replace(
    '</Types>',
    '<Override PartName="/word/charts/chart1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/><Override PartName="/word/diagrams/data1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.diagramData+xml"/><Override PartName="/word/commentsExtensible.xml" ContentType="application/vnd.ms-word.commentsExtensible+xml"/><Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/><Override PartName="/word/printerSettings/printerSettings1.bin" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.printerSettings"/><Override PartName="/docProps/thumbnail.emf" ContentType="image/x-emf"/></Types>',
    $passiveOptionalParts['[Content_Types].xml'],
);
$passiveOptionalDocx = package($passiveOptionalParts);
$passiveOptionalFacts = (new DocxPackageInspector())->inspect($passiveOptionalDocx);
assertTrue($passiveOptionalFacts->relationshipCount === 7, 'Bounded passive optional Word parts were rejected.');
$passiveOptionalCopy = (new DocxConversionSanitizer())->stripForConversion($passiveOptionalDocx);
(new DocxPackageInspector())->inspect($passiveOptionalCopy);
assertTrue(
    !isset(packageEntries($passiveOptionalCopy)['word/printerSettings/printerSettings1.bin']),
    'Printer settings reached the LibreOffice conversion copy.',
);
assertTrue(
    !isset(packageEntries($passiveOptionalCopy)['docProps/thumbnail.emf']),
    'The package thumbnail reached the LibreOffice conversion copy.',
);

$externalOptionalPart = $passiveOptionalParts;
$externalOptionalPart['word/_rels/document.xml.rels'] = str_replace(
    'Target="charts/chart1.xml"',
    'Target="https://example.com/chart.xml" TargetMode="External"',
    $externalOptionalPart['word/_rels/document.xml.rels'],
);
expectRejected(package($externalOptionalPart), 'An external chart relationship was accepted.');

$activeOptionalPart = $passiveOptionalParts;
$activeOptionalPart['word/activeX/activeX1.bin'] = 'active';
$activeOptionalPart['word/_rels/document.xml.rels'] = str_replace(
    '</Relationships>',
    '<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/control" Target="activeX/activeX1.bin"/></Relationships>',
    $activeOptionalPart['word/_rels/document.xml.rels'],
);
$activeOptionalPart['[Content_Types].xml'] = str_replace(
    '</Types>',
    '<Override PartName="/word/activeX/activeX1.bin" ContentType="application/vnd.ms-office.activeX"/></Types>',
    $activeOptionalPart['[Content_Types].xml'],
);
expectRejected(package($activeOptionalPart), 'An ActiveX part was accepted by the passive profile.');

$embeddedFont = package(embeddedFontEntries());
$embeddedFontFacts = (new DocxPackageInspector())->inspect($embeddedFont);
assertTrue($embeddedFontFacts->relationshipCount === 3, 'A bounded embedded-font DOCX was not accepted.');
$fontStripped = (new DocxConversionSanitizer())->stripForConversion($embeddedFont);
(new DocxPackageInspector())->inspect($fontStripped);
$fontStrippedEntries = packageEntries($fontStripped);
assertTrue(!isset($fontStrippedEntries['word/fonts/font1.odttf']), 'Embedded font bytes reached the conversion copy.');
assertTrue(
    !str_contains($fontStrippedEntries['word/fontTable.xml'] ?? '', 'embedRegular'),
    'The conversion copy retained an embedded-font reference.',
);
assertTrue(
    !str_contains($fontStrippedEntries['word/_rels/fontTable.xml.rels'] ?? '', '/font"'),
    'The conversion copy retained an embedded-font relationship.',
);
assertTrue(
    !str_contains($fontStrippedEntries['[Content_Types].xml'] ?? '', 'obfuscatedFont'),
    'The conversion copy retained an embedded-font content type.',
);
assertTrue(
    (new DocxConversionSanitizer())->stripForConversion($benign) === $benign,
    'A DOCX without embedded fonts was unnecessarily rewritten.',
);

$wrongEmbeddedFontContentType = embeddedFontEntries();
$wrongEmbeddedFontContentType['[Content_Types].xml'] = str_replace(
    'application/vnd.openxmlformats-officedocument.obfuscatedFont',
    'application/octet-stream',
    $wrongEmbeddedFontContentType['[Content_Types].xml'],
);
expectRejected(
    package($wrongEmbeddedFontContentType),
    'An embedded font with a generic content type was accepted.',
);

$externalEmbeddedFont = embeddedFontEntries();
$externalEmbeddedFont['word/_rels/fontTable.xml.rels'] = str_replace(
    'Target="fonts/font1.odttf"',
    'Target="https://example.com/font1.odttf" TargetMode="External"',
    $externalEmbeddedFont['word/_rels/fontTable.xml.rels'],
);
expectRejected(
    package($externalEmbeddedFont),
    'An externally hosted embedded font was accepted.',
);

$misboundEmbeddedFont = embeddedFontEntries();
$misboundEmbeddedFont['word/_rels/fontTable.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>';
$misboundEmbeddedFont['word/_rels/document.xml.rels'] = str_replace(
    '</Relationships>',
    '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/font" Target="fonts/font1.odttf"/></Relationships>',
    $misboundEmbeddedFont['word/_rels/document.xml.rels'],
);
expectRejected(
    package($misboundEmbeddedFont),
    'An embedded font relationship outside the font table was accepted.',
);

$tooManyEmbeddedFonts = embeddedFontEntries();
$fontRelationships = '';
unset($tooManyEmbeddedFonts['word/fonts/font1.odttf']);
for ($fontIndex = 1; $fontIndex <= 65; $fontIndex++) {
    $name = sprintf('font%d.odttf', $fontIndex);
    $tooManyEmbeddedFonts['word/fonts/' . $name] = 'font';
    $fontRelationships .= sprintf(
        '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/font" Target="fonts/%s"/>',
        $fontIndex,
        $name,
    );
}
$tooManyEmbeddedFonts['word/_rels/fontTable.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $fontRelationships . '</Relationships>';
expectRejectedWithMessage(
    package($tooManyEmbeddedFonts),
    'The DOCX package exceeds its embedded-font-count limit.',
    'The embedded-font-count limit did not produce its dedicated rejection.',
);

$explicitDirectories = package(baseEntries(), static function (ZipArchive $archive): void {
    assertTrue($archive->addEmptyDir('_rels'), 'Could not add the root relationships directory.');
    assertTrue($archive->addEmptyDir('word'), 'Could not add the Word directory.');
});
$explicitDirectoryFacts = (new DocxPackageInspector())->inspect($explicitDirectories);
assertTrue(
    $explicitDirectoryFacts->entryCount === 5,
    'Safe explicit DOCX directory records were not counted.',
);

$unknownDirectory = package(baseEntries(), static function (ZipArchive $archive): void {
    assertTrue($archive->addEmptyDir('unknown'), 'Could not add the unknown directory fixture.');
});
expectRejected($unknownDirectory, 'DOCX with an unknown explicit directory was accepted.');

$linked = baseEntries();
$linked['word/_rels/document.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://example.com/docs" TargetMode="External"/></Relationships>';
$linkFacts = (new DocxPackageInspector())->inspect(package($linked));
assertTrue($linkFacts->externalHyperlinkCount === 1, 'HTTPS link was not retained as a bounded fact.');

$representative = baseEntries();
$representative['word/styles.xml'] = '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>';
$representative['word/_rels/document.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://example.com/docs" TargetMode="External"/></Relationships>';
$representative['[Content_Types].xml'] = str_replace(
    '</Types>',
    '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>',
    $representative['[Content_Types].xml'],
);
$representativeFacts = (new DocxPackageInspector())->inspect(package($representative));
assertTrue($representativeFacts->relationshipCount === 3, 'Representative DOCX relationships were not accepted.');
assertTrue($representativeFacts->externalHyperlinkCount === 1, 'Representative DOCX hyperlink was not counted.');

$modernWordStyles = baseEntries();
$modernWordStyles['word/stylesWithEffects.xml'] = '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>';
$modernWordStyles['word/_rels/document.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.microsoft.com/office/2007/relationships/stylesWithEffects" Target="stylesWithEffects.xml"/></Relationships>';
$modernWordStyles['[Content_Types].xml'] = str_replace(
    '</Types>',
    '<Override PartName="/word/stylesWithEffects.xml" ContentType="application/vnd.ms-word.stylesWithEffects+xml"/></Types>',
    $modernWordStyles['[Content_Types].xml'],
);
$modernWordStylesFacts = (new DocxPackageInspector())->inspect(package($modernWordStyles));
assertTrue(
    $modernWordStylesFacts->relationshipCount === 2,
    'The passive styles-with-effects part emitted by modern Word was not accepted.',
);

$misboundModernWordStyles = $modernWordStyles;
$misboundModernWordStyles['word/_rels/document.xml.rels'] = str_replace(
    'Target="stylesWithEffects.xml"',
    'Target="document.xml"',
    $misboundModernWordStyles['word/_rels/document.xml.rels'],
);
expectRejected(
    package($misboundModernWordStyles),
    'A styles-with-effects relationship targeting the main document was accepted.',
);

$libreOfficeExport = baseEntries();
$libreOfficeExport['docProps/core.xml'] = '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"/>';
$libreOfficeExport['docProps/app.xml'] = '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"/>';
$libreOfficeExport['docProps/custom.xml'] = '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/custom-properties"/>';
$libreOfficeExport['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officedocument/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/custom-properties" Target="docProps/custom.xml"/><Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>';
$libreOfficeExport['[Content_Types].xml'] = str_replace(
    '</Types>',
    '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/><Override PartName="/docProps/custom.xml" ContentType="application/vnd.openxmlformats-officedocument.custom-properties+xml"/></Types>',
    $libreOfficeExport['[Content_Types].xml'],
);
$libreOfficeFacts = (new DocxPackageInspector())->inspect(package($libreOfficeExport));
assertTrue($libreOfficeFacts->relationshipCount === 4, 'LibreOffice DOCX metadata relationships were not accepted.');

$supportedProfile = baseEntries(
    '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body><w:p><w:pPr><w:numPr><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>List item</w:t></w:r></w:p><w:tbl><w:tr><w:tc><w:p><w:r><w:t>Cell</w:t></w:r></w:p></w:tc></w:tr></w:tbl><w:p><w:r><w:footnoteReference w:id="1"/><w:endnoteReference w:id="1"/></w:r></w:p><w:sectPr><w:headerReference r:id="rId2"/><w:footerReference r:id="rId3"/></w:sectPr></w:body></w:document>',
);
$supportedProfile['word/numbering.xml'] = '<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>';
$supportedProfile['word/header1.xml'] = '<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:r><w:t>Header</w:t></w:r></w:p></w:hdr>';
$supportedProfile['word/footer1.xml'] = '<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:p><w:r><w:t>Footer</w:t></w:r></w:p></w:ftr>';
$supportedProfile['word/footnotes.xml'] = '<w:footnotes xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:footnote w:id="1"><w:p><w:r><w:t>Footnote</w:t></w:r></w:p></w:footnote></w:footnotes>';
$supportedProfile['word/endnotes.xml'] = '<w:endnotes xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:endnote w:id="1"><w:p><w:r><w:t>Endnote</w:t></w:r></w:p></w:endnote></w:endnotes>';
$supportedProfile['word/media/pixel.png'] = testPng();
$supportedProfile['word/media/pixel.jpeg'] = testJpeg();
$supportedProfile['word/media/vector.emf'] = testEmf();
$supportedProfile['word/_rels/document.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/><Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footnotes" Target="footnotes.xml"/><Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/endnotes" Target="endnotes.xml"/><Relationship Id="rId6" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/pixel.png"/><Relationship Id="rId7" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/pixel.jpeg"/><Relationship Id="rId8" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/vector.emf"/></Relationships>';
$supportedProfile['[Content_Types].xml'] = str_replace(
    '</Types>',
    '<Default Extension="png" ContentType="image/png"/><Default Extension="jpeg" ContentType="image/jpeg"/><Default Extension="emf" ContentType="image/x-emf"/><Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/><Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/><Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/><Override PartName="/word/footnotes.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footnotes+xml"/><Override PartName="/word/endnotes.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.endnotes+xml"/></Types>',
    $supportedProfile['[Content_Types].xml'],
);
$supportedFacts = (new DocxPackageInspector())->inspect(package($supportedProfile));
assertTrue($supportedFacts->mediaCount === 3, 'The supported PNG/JPEG/EMF media profile was not accepted.');
assertTrue($supportedFacts->relationshipCount === 9, 'The supported DOCX relationship profile was not accepted.');

$standardEmfContentType = $supportedProfile;
$standardEmfContentType['[Content_Types].xml'] = str_replace(
    'ContentType="image/x-emf"',
    'ContentType="image/emf"',
    $standardEmfContentType['[Content_Types].xml'],
);
(new DocxPackageInspector())->inspect(package($standardEmfContentType));

$manySmallMedia = baseEntries();
$manySmallMediaRelationships = '';
for ($mediaIndex = 1; $mediaIndex <= 101; $mediaIndex++) {
    $mediaName = sprintf('image%03d.png', $mediaIndex);
    $manySmallMedia['word/media/' . $mediaName] = testPng();
    $manySmallMediaRelationships .= sprintf(
        '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/%s"/>',
        $mediaIndex,
        $mediaName,
    );
}
$manySmallMedia['word/_rels/document.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $manySmallMediaRelationships . '</Relationships>';
$manySmallMedia['[Content_Types].xml'] = str_replace(
    '</Types>',
    '<Default Extension="png" ContentType="image/png"/></Types>',
    $manySmallMedia['[Content_Types].xml'],
);
$manySmallMediaFacts = (new DocxPackageInspector())->inspect(package($manySmallMedia));
assertTrue($manySmallMediaFacts->mediaCount === 101, 'A bounded DOCX with many small images was rejected.');

$excessiveMediaCount = baseEntries();
for ($mediaIndex = 1; $mediaIndex <= 1_025; $mediaIndex++) {
    $excessiveMediaCount[sprintf('word/media/image%04d.png', $mediaIndex)] = testPng();
}
expectRejectedWithMessage(
    package($excessiveMediaCount),
    'The DOCX package exceeds its media-count limit.',
    'The DOCX media-count limit did not produce its dedicated rejection.',
);

$excessiveMediaPixels = baseEntries();
for ($mediaIndex = 1; $mediaIndex <= 17; $mediaIndex++) {
    $excessiveMediaPixels[sprintf('word/media/image%02d.png', $mediaIndex)] = testPngWithDimensions(4_096, 4_096);
}
expectRejectedWithMessage(
    package($excessiveMediaPixels),
    'The DOCX package exceeds its aggregate media-pixel limit.',
    'The DOCX aggregate media-pixel limit did not produce its dedicated rejection.',
);

foreach ([
    'gif' => ['image/gif', 'GIF89a'],
    'bmp' => ['image/bmp', "BM\x00\x00"],
    'tiff' => ['image/tiff', "II*\x00"],
    'wmf' => ['application/x-wmf', "\xD7\xCD\xC6\x9A"],
    'svg' => ['image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>'],
] as $extension => [$contentType, $mediaBytes]) {
    $unsupportedMedia = baseEntries();
    $unsupportedMedia['word/media/payload.' . $extension] = $mediaBytes;
    $unsupportedMedia['word/_rels/document.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/payload.' . $extension . '"/></Relationships>';
    $unsupportedMedia['[Content_Types].xml'] = str_replace(
        '</Types>',
        '<Default Extension="' . $extension . '" ContentType="' . $contentType . '"/></Types>',
        $unsupportedMedia['[Content_Types].xml'],
    );
    expectRejectedWithMessage(
        package($unsupportedMedia),
        'The DOCX package contains an unsupported media format.',
        strtoupper($extension) . ' media was accepted outside the PNG/JPEG profile.',
    );
}

$mislabeledEmf = $supportedProfile;
$mislabeledEmf['word/media/vector.emf'] = testPng();
expectRejected(package($mislabeledEmf), 'PNG bytes mislabeled as EMF were accepted.');

$wrongEmfContentType = $supportedProfile;
$wrongEmfContentType['[Content_Types].xml'] = str_replace(
    'ContentType="image/x-emf"',
    'ContentType="application/x-msmetafile"',
    $wrongEmfContentType['[Content_Types].xml'],
);
expectRejected(package($wrongEmfContentType), 'An unsupported legacy EMF content type was accepted.');

$truncatedEmf = $supportedProfile;
$truncatedEmf['word/media/vector.emf'] = substr(testEmf(), 0, -4);
expectRejected(package($truncatedEmf), 'A truncated EMF record stream was accepted.');

$wrongEmfRecordCount = $supportedProfile;
$wrongEmfRecordCount['word/media/vector.emf'] = substr_replace(testEmf(), pack('V', 4), 52, 4);
expectRejected(package($wrongEmfRecordCount), 'An EMF with a false record count was accepted.');

$emfWithDriverEscape = $supportedProfile;
$emfWithDriverEscape['word/media/vector.emf'] = substr_replace(
    testEmf(),
    pack('V*', 105, 24, 10, 10, 90, 90),
    88,
    24,
);
expectRejected(package($emfWithDriverEscape), 'An EMF driver-escape record was accepted.');

$unknownWordPart = baseEntries();
$unknownWordPart['word/privateDocumentDetail.dat'] = 'private';
try {
    (new DocxPackageInspector())->inspect(package($unknownWordPart));
    throw new RuntimeException('An unknown Word package part was accepted.');
} catch (ProcessorRejection $exception) {
    assertTrue(
        $exception->getMessage() === 'The DOCX package contains an unsupported Word part.',
        'Unknown Word parts did not use the privacy-safe diagnostic category.',
    );
    assertTrue(
        preg_match('/\A[a-f0-9]{12}\z/', $exception->diagnosticContextCode() ?? '') === 1,
        'Unknown Word parts did not retain an opaque package-part diagnostic.',
    );
}

$embeddedWorkbook = baseEntries();
$embeddedWorkbook['word/embeddings/Microsoft_Excel_Worksheet.xlsx'] = 'opaque embedded workbook';
try {
    (new DocxPackageInspector())->inspect(package($embeddedWorkbook));
    throw new RuntimeException('An embedded workbook was accepted.');
} catch (ProcessorRejection $exception) {
    assertTrue(
        $exception->publicReasonCode() === 'embedded_file',
        'An embedded workbook did not expose its fixed safe rejection reason.',
    );
}

$mislabeledPng = $supportedProfile;
$mislabeledPng['word/media/pixel.png'] = testJpeg();
expectRejected(package($mislabeledPng), 'JPEG bytes mislabeled as PNG were accepted.');

$unsafeLink = $linked;
$unsafeLink['word/_rels/document.xml.rels'] = str_replace('https://example.com/docs', 'javascript:alert(1)', $linked['word/_rels/document.xml.rels']);
expectRejected(package($unsafeLink), 'Unsafe external hyperlink was accepted.');

$ambiguousHttpLink = $linked;
$ambiguousHttpLink['word/_rels/document.xml.rels'] = str_replace('https://example.com/docs', 'http:relative', $linked['word/_rels/document.xml.rels']);
expectRejected(package($ambiguousHttpLink), 'Ambiguous HTTP hyperlink was accepted.');

$localFileRelationship = baseEntries();
$localFileRelationship['word/_rels/document.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="file:///etc/passwd"/></Relationships>';
expectRejected(package($localFileRelationship), 'Local-file relationship target was accepted.');

$escapingRelationship = baseEntries();
$escapingRelationship['word/_rels/document.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../../etc/passwd"/></Relationships>';
expectRejected(package($escapingRelationship), 'Relationship target escaped the package root.');

$wrongRelationshipNamespace = baseEntries();
$wrongRelationshipNamespace['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="urn:attacker"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>';
expectRejected(package($wrongRelationshipNamespace), 'Decoy relationship namespace was accepted.');

$spoofedRelationshipType = baseEntries();
$spoofedRelationshipType['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="https://attacker.example/officeDocument" Target="word/document.xml"/></Relationships>';
expectRejected(package($spoofedRelationshipType), 'Suffix-only relationship type spoof was accepted.');

$duplicateMainRelationship = baseEntries();
$duplicateMainRelationship['word/styles.xml'] = '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>';
$duplicateMainRelationship['_rels/.rels'] = str_replace(
    '</Relationships>',
    '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/styles.xml"/></Relationships>',
    $duplicateMainRelationship['_rels/.rels'],
);
expectRejected(package($duplicateMainRelationship), 'Ambiguous root office-document graph was accepted.');

$wrongTypedTarget = baseEntries();
$wrongTypedTarget['word/_rels/document.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="document.xml"/></Relationships>';
expectRejected(package($wrongTypedTarget), 'Relationship type was allowed to target an incompatible part.');

$unreferencedPart = baseEntries();
$unreferencedPart['word/styles.xml'] = '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>';
expectRejected(package($unreferencedPart), 'Unreferenced WordprocessingML part was accepted.');

$orphanRelationshipSource = baseEntries();
$orphanRelationshipSource['word/_rels/styles.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://example.com" TargetMode="External"/></Relationships>';
expectRejected(package($orphanRelationshipSource), 'Relationship part without a source part was accepted.');

$wrongContentTypeNamespace = baseEntries();
$wrongContentTypeNamespace['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="urn:attacker"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>';
expectRejected(package($wrongContentTypeNamespace), 'Decoy content-type namespace was accepted.');

$macro = baseEntries();
$macro['[Content_Types].xml'] = str_replace('document.main+xml', 'document.macroEnabled.main+xml', $macro['[Content_Types].xml']);
expectRejected(package($macro), 'Macro-enabled main type was accepted.');

$mismatchedContentType = baseEntries();
$mismatchedContentType['word/styles.xml'] = '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>';
$mismatchedContentType['[Content_Types].xml'] = str_replace(
    '</Types>',
    '<Override PartName="/word/styles.xml" ContentType="application/javascript"/></Types>',
    $mismatchedContentType['[Content_Types].xml'],
);
expectRejected(package($mismatchedContentType), 'Incompatible content type was accepted for a WordprocessingML part.');

$genericTypedPart = baseEntries();
$genericTypedPart['word/styles.xml'] = '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>';
$genericTypedPart['word/_rels/document.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
expectRejected(package($genericTypedPart), 'A typed WordprocessingML part declared only as generic XML was accepted.');

$activeField = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:instrText>INCLUDETEXT https://example.test/a</w:instrText></w:body></w:document>';
expectRejected(package(baseEntries($activeField)), 'External-content field was accepted.');

$splitActiveField = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:instrText>INCLUDE</w:instrText><w:instrText>TEXT https://example.test/a</w:instrText></w:body></w:document>';
expectRejected(package(baseEntries($splitActiveField)), 'Split external-content field was accepted.');

$doctype = baseEntries('<!DOCTYPE a [<!ENTITY x SYSTEM "file:///etc/passwd">]><w:document xmlns:w="urn:w"><w:body>&x;</w:body></w:document>');
expectRejected(package($doctype), 'DTD/entity DOCX was accepted.');

expectRejected($benign . 'trailing-container', 'DOCX with trailing container bytes was accepted.');

$ambiguousFooter = package(baseEntries(), static function (ZipArchive $archive): void {
    $updated = $archive->setCommentName('word/document.xml', str_repeat('X', 22));
    assertTrue($updated, 'Could not add the ambiguous EOCD fixture marker.');
});
$fakeFooterOffset = strpos($ambiguousFooter, str_repeat('X', 22));
if (!is_int($fakeFooterOffset)) {
    throw new RuntimeException('Could not locate the ambiguous EOCD fixture marker.');
}
$fakeCommentLength = strlen($ambiguousFooter) - $fakeFooterOffset - 22;
$fakeFooter = pack(
    'VvvvvVVv',
    0x06054b50,
    0,
    0,
    count(baseEntries()),
    count(baseEntries()),
    $fakeFooterOffset,
    0,
    $fakeCommentLength,
);
$ambiguousFooter = substr_replace($ambiguousFooter, $fakeFooter, $fakeFooterOffset, 22);
expectRejected($ambiguousFooter, 'DOCX with multiple plausible EOCD records was accepted.');

$symlinkLike = package(baseEntries(), static function (ZipArchive $archive): void {
    $updated = $archive->setExternalAttributesName(
        'word/document.xml',
        ZipArchive::OPSYS_UNIX,
        0120777 << 16,
    );
    assertTrue($updated, 'Could not mark the DOCX fixture as a Unix symlink.');
});
expectRejected($symlinkLike, 'DOCX with a symlink-like package entry was accepted.');

$unsupportedCompression = package(baseEntries(), static function (ZipArchive $archive): void {
    $updated = $archive->setCompressionName('word/document.xml', ZipArchive::CM_BZIP2);
    assertTrue($updated, 'Could not apply unsupported compression to the DOCX fixture.');
});
expectRejected($unsupportedCompression, 'DOCX with an unsupported compression method was accepted.');

$secret = str_repeat('s', 40);
$configuration = new ProcessorConfiguration($secret);
$timestamp = '1700000000';
$healthNonce = str_repeat('9', 32);
$healthServer = [
    'HTTP_X_ARTIFACTFLOW_PROCESSOR_TIMESTAMP' => $timestamp,
    'HTTP_X_ARTIFACTFLOW_PROCESSOR_NONCE' => $healthNonce,
    'HTTP_X_ARTIFACTFLOW_PROCESSOR_SIGNATURE' => ProcessorHealthRequest::signature(
        $timestamp,
        $healthNonce,
        $secret,
    ),
];
$healthRequest = ProcessorHealthRequest::authenticated($configuration, $healthServer, (int) $timestamp);
assertTrue($healthRequest->nonce === $healthNonce, 'Authenticated DOCX health challenge was not accepted.');
$healthBody = '{"status":"ok"}';
assertTrue(
    ProcessorHealthRequest::responseSignature($healthNonce, $healthBody, $secret)
        === hash_hmac('sha256', implode("\n", [
            'artifactflow-docx-processor-health-response-v1',
            $healthNonce,
            'application/json',
            (string) strlen($healthBody),
            hash('sha256', $healthBody),
        ]), $secret),
    'DOCX health response signature does not bind the challenge nonce and exact body.',
);
try {
    ProcessorHealthRequest::authenticated($configuration, $healthServer, (int) $timestamp);
    throw new RuntimeException('Replayed DOCX health challenge was accepted.');
} catch (ProcessorAuthenticationFailure) {
    // Expected.
}

$forgedHealth = $healthServer;
$forgedHealth['HTTP_X_ARTIFACTFLOW_PROCESSOR_NONCE'] = str_repeat('8', 32);
try {
    ProcessorHealthRequest::authenticated($configuration, $forgedHealth, (int) $timestamp);
    throw new RuntimeException('Forged DOCX health challenge was accepted.');
} catch (ProcessorAuthenticationFailure) {
    // Expected.
}

$nonce = str_repeat('a', 32);
$hash = hash('sha256', $benign);
$server = [
    'HTTP_X_ARTIFACTFLOW_PROCESSOR_TIMESTAMP' => $timestamp,
    'HTTP_X_ARTIFACTFLOW_PROCESSOR_NONCE' => $nonce,
    'HTTP_X_ARTIFACTFLOW_PROCESSOR_PROFILE' => ProcessorRequest::PROFILE,
    'HTTP_X_ARTIFACTFLOW_INPUT_SHA256' => $hash,
    'CONTENT_TYPE' => ProcessorRequest::MEDIA_TYPE,
    'CONTENT_LENGTH' => (string) strlen($benign),
];
$server['HTTP_X_ARTIFACTFLOW_PROCESSOR_SIGNATURE'] = hash_hmac('sha256', implode("\n", [
    'artifactflow-docx-processor-request-v1',
    $timestamp,
    $nonce,
    ProcessorRequest::PROFILE,
    ProcessorRequest::MEDIA_TYPE,
    (string) strlen($benign),
    $hash,
]), $secret);
$request = ProcessorRequest::authenticated($configuration, $server, $benign, (int) $timestamp);
assertTrue($request->inputSha256 === $hash, 'Authenticated request did not bind input bytes.');
assertTrue(
    ProcessorRequest::validatedDeclaredLength($server) === strlen($benign),
    'DOCX request length preflight did not accept the exact bounded body length.',
);

$oversizedEnvelope = $server;
$oversizedEnvelope['CONTENT_LENGTH'] = (string) (ProcessorConfiguration::MAX_INPUT_BYTES + 1);
try {
    ProcessorRequest::validatedDeclaredLength($oversizedEnvelope);
    throw new RuntimeException('Oversized DOCX request was not rejected before its body was read.');
} catch (ProcessorAuthenticationFailure) {
    // Expected.
}

$tampered = $server;
$tampered['CONTENT_LENGTH'] = (string) (strlen($benign) + 1);
try {
    ProcessorRequest::authenticated($configuration, $tampered, $benign, (int) $timestamp);
    throw new RuntimeException('Tampered request envelope was accepted.');
} catch (ProcessorAuthenticationFailure) {
    // Expected.
}

echo "DOCX processor contract tests passed\n";
