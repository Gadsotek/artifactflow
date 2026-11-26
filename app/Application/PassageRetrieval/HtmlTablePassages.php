<?php

declare(strict_types=1);

namespace App\Application\PassageRetrieval;

use Dom\Element;
use Generator;

final class HtmlTablePassages
{
    /**
     * Recognize a rectangular table with one column-header row and a leading
     * row header. Validate the whole grid before assigning any column index.
     *
     * @return Generator<int, array{HtmlPassageText, HtmlPassageText, HtmlPassageText}>
     */
    public function cells(Element $table, HtmlTextReader $reader, PassageCollector $collector): Generator
    {
        $elements = iterator_to_array($this->rows($table), false);
        $first = array_shift($elements);
        if ($first === null) {
            return;
        }
        $headers = $this->rowCells($first, headerRow: true);
        if (count($headers) < 2) {
            $collector->markLimited();

            return;
        }
        $rows = [];
        foreach ($elements as $row) {
            if (!$collector->advance()) {
                return;
            }
            $cells = $this->rowCells($row, headerRow: false);
            if (count($cells) !== count($headers)) {
                $collector->markLimited();

                return;
            }
            $rows[] = $cells;
        }

        $columns = [];
        foreach ($headers as $index => $header) {
            if ($index > 0) {
                $columns[$index] = $reader->single($header);
            }
        }

        foreach ($rows as $cells) {
            $rowHeader = $reader->single($cells[0]);
            foreach ($columns as $index => $column) {
                if (!$collector->advance()) {
                    return;
                }
                $value = $reader->single($cells[$index]);
                if ($value === null) {
                    continue;
                }
                if ($column === null || $rowHeader === null) {
                    $collector->markLimited();
                    continue;
                }
                yield [$rowHeader, $column, $value];
            }
        }
    }

    /** @return list<Element> */
    private function rowCells(Element $row, bool $headerRow): array
    {
        $cells = [];
        foreach ($row->children as $index => $cell) {
            $header = $headerRow || $index === 0;
            $scope = strtolower(trim($cell->getAttribute('scope') ?? ''));
            if ($cell->localName !== ($header ? 'th' : 'td')
                || ($scope !== '' && (!$header || $scope !== ($headerRow ? 'col' : 'row')))
                || $cell->hasAttribute('headers')
                || ($cell->getAttribute('rowspan') ?? '1') !== '1'
                || ($cell->getAttribute('colspan') ?? '1') !== '1'
                || $cell->getElementsByTagName('table')->length !== 0
                || $index >= 64) {
                return [];
            }
            $cells[] = $cell;
        }

        return $cells;
    }

    /** @return Generator<int, Element> */
    private function rows(Element $parent): Generator
    {
        foreach ($parent->children as $child) {
            if (HtmlSourceDocument::excluded($child)) {
                continue;
            }
            if ($child->localName === 'tr') {
                yield $child;
            } elseif (in_array($child->localName, ['thead', 'tbody', 'tfoot'], true)) {
                yield from $this->rows($child);
            }
        }
    }
}
