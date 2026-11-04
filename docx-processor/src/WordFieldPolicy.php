<?php

declare(strict_types=1);

namespace ArtifactFlow\DocxProcessor;

use DOMDocument;
use DOMElement;
use DOMXPath;

/** Field instructions can span runs and nest; arguments are never command names. */
final class WordFieldPolicy
{
    private const array NAMESPACES = [
        'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
        'http://purl.oclc.org/ooxml/wordprocessingml/main',
    ];

    public static function process(string $xml, bool $stripHyperlinks = false): string
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded || $document->doctype !== null) {
            throw new ProcessorRejection('The DOCX field document is malformed.');
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', self::NAMESPACES[0]);
        $xpath->registerNamespace('s', self::NAMESPACES[1]);
        $nodes = $xpath->query('//w:fldSimple | //s:fldSimple | //w:fldChar | //s:fldChar | //w:instrText | //s:instrText | //w:altChunk | //s:altChunk');
        if ($nodes === false) {
            throw new ProcessorRejection('The DOCX field document could not be inspected.');
        }
        $frames = [];
        $remove = [];
        $unwrap = [];
        $instructionBytes = 0;
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            if ($node->localName === 'altChunk') {
                throw new ProcessorRejection('The DOCX package contains prohibited alternate content.');
            }
            if ($node->localName === 'fldSimple') {
                self::requireStaticParentCommand($frames);
                $instruction = self::attribute($node, 'instr');
                $instructionBytes += strlen($instruction);
                if (self::command($instruction) === 'HYPERLINK') {
                    $unwrap[] = $node;
                }
            } elseif ($node->localName === 'fldChar') {
                $kind = self::attribute($node, 'fldCharType');
                if ($kind === 'begin') {
                    self::requireStaticParentCommand($frames);
                    if (count($frames) >= 128) {
                        throw new ProcessorRejection('The DOCX fields exceed their nesting limit.');
                    }
                    $frames[] = ['instruction' => '', 'separated' => false, 'nodes' => [$node]];
                } elseif ($frames === [] || !in_array($kind, ['separate', 'end'], true)) {
                    throw new ProcessorRejection('The DOCX field structure is ambiguous.');
                } else {
                    $index = count($frames) - 1;
                    $frames[$index]['nodes'][] = $node;
                    $command = self::command($frames[$index]['instruction']);
                    if ($kind === 'separate') {
                        if ($frames[$index]['separated']) {
                            throw new ProcessorRejection('The DOCX field structure is ambiguous.');
                        }
                        $frames[$index]['separated'] = true;
                    } else {
                        $frame = array_pop($frames);
                        if ($command === 'HYPERLINK') {
                            array_push($remove, ...$frame['nodes']);
                        }
                    }
                }
            } else {
                $index = count($frames) - 1;
                if ($index < 0 || $frames[$index]['separated']) {
                    throw new ProcessorRejection('The DOCX field instruction has no unambiguous owner.');
                }
                $instruction = $node->textContent;
                $instructionBytes += strlen($instruction);
                $frames[$index]['instruction'] .= $instruction;
                $frames[$index]['nodes'][] = $node;
            }
            if ($instructionBytes > 1_048_576) {
                throw new ProcessorRejection('The DOCX field instructions exceed their limit.');
            }
        }
        if ($frames !== []) {
            throw new ProcessorRejection('The DOCX field structure is incomplete.');
        }
        if (!$stripHyperlinks || ($remove === [] && $unwrap === [])) {
            return $xml;
        }
        foreach ($remove as $node) {
            $node->parentNode?->removeChild($node);
        }
        foreach ($unwrap as $node) {
            $parent = $node->parentNode;
            if ($parent === null) {
                continue;
            }
            while ($node->firstChild !== null) {
                $parent->insertBefore($node->firstChild, $node);
            }
            $parent->removeChild($node);
        }
        $result = $document->saveXML();
        if (!is_string($result)) {
            throw new ProcessorRejection('The DOCX cached field display could not be preserved.');
        }

        return $result;
    }

    private static function attribute(DOMElement $element, string $name): string
    {
        $values = [];
        foreach (self::NAMESPACES as $namespace) {
            if ($element->hasAttributeNS($namespace, $name)) {
                $values[] = $element->getAttributeNS($namespace, $name);
            }
        }
        if (count($values) !== 1) {
            throw new ProcessorRejection('The DOCX field attribute is ambiguous.');
        }

        return $values[0];
    }

    private static function command(string $instruction): string
    {
        if (preg_match('/\A[\x20\t\r\n]*(=|[A-Za-z][A-Za-z0-9]*(?=[\x20\t\r\n\\\\"]|\z))/', $instruction, $matches) !== 1) {
            throw new ProcessorRejection('The DOCX field command is malformed.');
        }
        $command = strtoupper($matches[1]);
        if (in_array($command, ['DDE', 'DDEAUTO', 'INCLUDETEXT', 'INCLUDEPICTURE', 'DATABASE', 'LINK'], true)) {
            throw new ProcessorRejection('The DOCX package contains a prohibited active field.');
        }

        return $command;
    }

    /** @param list<array{instruction: string, separated: bool, nodes: list<DOMElement>}> $frames */
    private static function requireStaticParentCommand(array $frames): void
    {
        if ($frames === []) {
            return;
        }
        $parent = $frames[count($frames) - 1];
        if (
            !$parent['separated']
            && preg_match('/\A[\x20\t\r\n]*(?:=|[A-Za-z][A-Za-z0-9]*[\x20\t\r\n])/', $parent['instruction']) !== 1
        ) {
            throw new ProcessorRejection('The DOCX field command cannot be assembled from nested fields.');
        }
    }
}
