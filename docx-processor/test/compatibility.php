<?php

declare(strict_types=1);

use ArtifactFlow\DocxProcessor\DocxConversionSanitizer;
use ArtifactFlow\DocxProcessor\DocxPackageInspector;
use ArtifactFlow\DocxProcessor\ProcessorConfiguration;
use ArtifactFlow\DocxProcessor\ProcessorRejection;

function fieldDocument(string $body): string
{
    return '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p>' . $body . '</w:p></w:body></w:document>';
}

function complexField(string $instruction, string $result = 'Cached result'): string
{
    return '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
        . '<w:r><w:instrText>' . htmlspecialchars($instruction, ENT_XML1) . '</w:instrText></w:r>'
        . '<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
        . '<w:r><w:t>' . $result . '</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r>';
}

$compatibilityCases = [
    'field commands are independent of bookmark names and other fields' => static function (): void {
        foreach (['REF ClientLink', 'REF Hidden', 'REF Database', 'PAGE', 'REF Link', 'REF DDE', '=SUM(ABOVE)'] as $instruction) {
            foreach ([
                '<w:fldSimple w:instr="' . $instruction . '"><w:r><w:t>Cached result</w:t></w:r></w:fldSimple>',
                complexField($instruction),
            ] as $field) {
                (new DocxPackageInspector())->inspect(package(baseEntries(fieldDocument($field))));
            }
        }
        $nested = complexField('IF ', 'Outer result');
        $nested = str_replace('<w:instrText>IF </w:instrText>', '<w:instrText>IF </w:instrText></w:r>' . complexField('REF Hidden', 'Nested result') . '<w:r><w:instrText> = 1 "yes" "no"</w:instrText>', $nested);
        (new DocxPackageInspector())->inspect(package(baseEntries(fieldDocument($nested))));
    },
    'hyperlink fields retain cached display without converter actions' => static function (): void {
        foreach (['HYPERLINK "https://example.com/"', 'HYPERLINK \\l "ClientLink"'] as $instruction) {
            foreach ([
                complexField($instruction),
                '<w:fldSimple w:instr="' . htmlspecialchars($instruction, ENT_XML1 | ENT_QUOTES) . '"><w:r><w:t>Cached result</w:t></w:r></w:fldSimple>',
            ] as $field) {
                $input = package(baseEntries(fieldDocument($field)));
                (new DocxPackageInspector())->inspect($input);
                $copy = (new DocxConversionSanitizer())->stripForConversion($input);
                (new DocxPackageInspector())->inspect($copy);
                $xml = packageEntries($copy)['word/document.xml'];
                assertTrue(str_contains($xml, 'Cached result'), 'Hyperlink display was lost.');
                assertTrue(!str_contains($xml, 'HYPERLINK') && !str_contains($xml, 'fldChar'), 'Hyperlink action reached the converter.');
            }
        }
    },
    'UTF-16 hyperlink fields retain cached display without converter actions' => static function (): void {
        foreach (['UTF-16LE' => "\xFF\xFE", 'UTF-16BE' => "\xFE\xFF"] as $encoding => $bom) {
            foreach ([
                complexField('HYPERLINK "https://example.com/"'),
                '<w:fldSimple w:instr="HYPERLINK &quot;https://example.com/&quot;"><w:r><w:t>Cached result</w:t></w:r></w:fldSimple>',
            ] as $field) {
                $xml = '<?xml version="1.0" encoding="UTF-16"?>' . fieldDocument($field);
                $encoded = iconv('UTF-8', $encoding, $xml);
                if (!is_string($encoded)) {
                    throw new RuntimeException('UTF-16 test document could not be encoded.');
                }

                $input = package(baseEntries($bom . $encoded));
                (new DocxPackageInspector())->inspect($input);
                $copy = (new DocxConversionSanitizer())->stripForConversion($input);
                (new DocxPackageInspector())->inspect($copy);
                $document = new DOMDocument();
                assertTrue(
                    $document->loadXML(packageEntries($copy)['word/document.xml'], LIBXML_NONET),
                    'The UTF-16 conversion copy is not valid XML.',
                );
                $xpath = new DOMXPath($document);
                $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
                $fields = $xpath->query('//w:fldSimple | //w:fldChar | //w:instrText');
                assertTrue($fields !== false && $fields->length === 0, 'A UTF-16 hyperlink action reached the converter.');
                assertTrue(str_contains($document->textContent, 'Cached result'), 'The UTF-16 hyperlink display was lost.');
            }
        }
    },
    'active commands remain rejected across split and nested fields' => static function (): void {
        foreach (['DDE', 'DDEAUTO', 'INCLUDETEXT', 'INCLUDEPICTURE', 'DATABASE', 'LINK'] as $command) {
            $field = complexField($command . ' "example"');
            $split = str_replace($command, substr($command, 0, 2) . '</w:instrText></w:r><w:r><w:instrText>' . substr($command, 2), $field);
            expectRejected(package(baseEntries(fieldDocument($split))), 'Split active command was accepted.');
            expectRejected(package(baseEntries(fieldDocument('<w:fldSimple w:instr="REF Safe">' . $field . '</w:fldSimple>'))), 'Nested active command was accepted.');
        }
        expectRejected(package(baseEntries(fieldDocument('<w:r><w:fldChar w:fldCharType="end"/></w:r>'))), 'Unbalanced field was accepted.');
        $dynamicCommand = str_replace('<w:instrText>REF Safe</w:instrText>', '<w:instrText>D</w:instrText></w:r>' . complexField('REF Name', 'DE') . '<w:r><w:instrText> "target"</w:instrText>', complexField('REF Safe'));
        expectRejected(package(baseEntries(fieldDocument($dynamicCommand))), 'Dynamically assembled command was accepted.');
    },
    'alternate content cannot hide active fields from the converter' => static function (): void {
        $namespace = ' xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006"'
            . ' xmlns:unsupported="urn:artifactflow:unsupported-review-probe"';
        foreach (['DDE', 'DDEAUTO', 'INCLUDETEXT', 'INCLUDEPICTURE', 'DATABASE', 'LINK'] as $command) {
            $field = '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
                . '<mc:AlternateContent><mc:Choice Requires="unsupported">'
                . '<w:r><w:instrText xml:space="preserve">REF Safe </w:instrText></w:r>'
                . '</mc:Choice><mc:Fallback><w:r><w:instrText xml:space="preserve">'
                . htmlspecialchars($command . ' "/tmp/review-canary"', ENT_XML1)
                . '</w:instrText></w:r></mc:Fallback></mc:AlternateContent>'
                . '<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
                . '<w:r><w:t>Cached result</w:t></w:r>'
                . '<w:r><w:fldChar w:fldCharType="end"/></w:r>';
            $document = str_replace('<w:document xmlns:w=', '<w:document' . $namespace . ' xmlns:w=', fieldDocument($field));
            $input = package(baseEntries($document));
            expectRejected($input, 'Alternate content hid an active ' . $command . ' field from preflight.');
            $aliased = str_replace(
                ['xmlns:mc=', '<mc:', '</mc:'],
                ['xmlns:compat=', '<compat:', '</compat:'],
                $document,
            );
            expectRejected(package(baseEntries($aliased)), 'An aliased alternate-content prefix hid an active field.');
            try {
                (new DocxConversionSanitizer())->stripForConversion($input);
            } catch (ProcessorRejection) {
                continue;
            }
            throw new RuntimeException('Alternate content passed an active ' . $command . ' field to the converter.');
        }

        $ordinary = '<mc:AlternateContent><mc:Choice Requires="unsupported"><w:r><w:t>Choice</w:t></w:r>'
            . '</mc:Choice><mc:Fallback><w:r><w:t>Fallback</w:t></w:r></mc:Fallback></mc:AlternateContent>';
        $document = str_replace('<w:document xmlns:w=', '<w:document' . $namespace . ' xmlns:w=', fieldDocument($ordinary));
        (new DocxPackageInspector())->inspect(package(baseEntries($document)));
    },
    'equivalent bounded internal relationships are accepted' => static function (): void {
        foreach (['Id="R1"', 'TargetMode="Internal" Id="rId1"', 'Id="rId1"'] as $id) {
            foreach (['word/document.xml', '/word/document.xml'] as $target) {
                $entries = baseEntries();
                $entries['_rels/.rels'] = str_replace(['Id="rId1"', 'Target="word/document.xml"'], [$id, 'Target="' . $target . '"'], $entries['_rels/.rels']);
                (new DocxPackageInspector())->inspect(package($entries));
            }
        }
        foreach (['//word/document.xml', '/../word/document.xml', 'https://example.com/document.xml', '/word/%2e%2e/document.xml'] as $target) {
            $entries = baseEntries();
            $entries['_rels/.rels'] = str_replace('Target="word/document.xml"', 'TargetMode="Internal" Target="' . $target . '"', $entries['_rels/.rels']);
            expectRejected(package($entries), 'Unsafe internal target was accepted.');
        }
    },
    'bounded repetitive content is accepted without relaxing expanded byte limits' => static function (): void {
        $xml = fieldDocument(str_repeat('<w:r><w:t>Repeated ordinary report text.</w:t></w:r>', 2000));
        $input = package(baseEntries($xml));
        assertTrue(strlen($xml) / strlen(gzdeflate($xml, 9)) > 200, 'Fixture does not exercise the former ratio cutoff.');
        (new DocxPackageInspector())->inspect($input);
        $oversized = fieldDocument('<w:r><w:t>' . str_repeat('X', ProcessorConfiguration::MAX_EXPANDED_BYTES) . '</w:t></w:r>');
        expectRejected(package(baseEntries($oversized)), 'Expanded-byte limit was removed.');
    },
];
$compatibilityFailures = [];
foreach ($compatibilityCases as $name => $case) {
    try {
        $case();
        echo 'PASS DOCX compatibility: ' . $name . "\n";
    } catch (Throwable $failure) {
        $compatibilityFailures[] = $name . ': ' . $failure->getMessage();
    }
}
assertTrue($compatibilityFailures === [], implode("\n", $compatibilityFailures));
