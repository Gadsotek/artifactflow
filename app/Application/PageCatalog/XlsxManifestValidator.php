<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PageContentEncoding;
use JsonException;
use RuntimeException;

final class XlsxManifestValidator
{
    private const int MAX_SHEETS = 20;

    private const int MAX_ROWS = 20_000;

    private const int MAX_COLUMNS = 256;

    private const int MAX_CELLS = 100_000;

    private const int MAX_MERGES = 1_000;

    private const int MAX_SHEET_NAME_BYTES = 512;

    private const int MAX_STRING_BYTES = 1024 * 1024;

    private const int MAX_FORMULA_BYTES = 8_192;

    private const int MAX_LINK_BYTES = 8_192;

    /**
     * @param array<string, mixed> $manifest
     */
    public function validate(array $manifest): XlsxManifestValidationResult
    {
        $this->assertExactKeys($manifest, ['profile', 'schema', 'searchText', 'sheets', 'workbook']);

        if (
            ($manifest['schema'] ?? null) !== 'xlsx-view-manifest-v1'
            || ($manifest['profile'] ?? null) !== XlsxProcessorProtocol::PROCESSOR_PROFILE
        ) {
            throw new RuntimeException('XLSX manifest uses an unsupported schema or profile.');
        }

        $workbook = $this->object($manifest['workbook'] ?? null, 'XLSX workbook facts have an invalid shape.');
        $sheets = $this->list($manifest['sheets'] ?? null, 'XLSX worksheets have an invalid shape.');
        $searchText = $manifest['searchText'] ?? null;

        if (
            $sheets === []
            || count($sheets) > self::MAX_SHEETS
            || !is_string($searchText)
            || strlen($searchText) > XlsxProcessorConfiguration::MAX_SEARCH_TEXT_BYTES
            || !PageContentEncoding::isStorable($searchText)
        ) {
            throw new RuntimeException('XLSX manifest contains invalid workbook values.');
        }

        $this->assertExactKeys($workbook, [
            'formulaCount',
            'formulasWithoutCachedResultCount',
            'linkCount',
            'cellCount',
            'mergeCount',
            'omittedHiddenSheetCount',
            'truncated',
            'visibleSheetCount',
        ]);

        $visibleSheetCount = $this->boundedInteger($workbook['visibleSheetCount'] ?? null, 1, self::MAX_SHEETS);
        $omittedHiddenSheetCount = $this->boundedInteger(
            $workbook['omittedHiddenSheetCount'] ?? null,
            0,
            self::MAX_SHEETS,
        );
        $declaredFormulaCount = $this->boundedInteger($workbook['formulaCount'] ?? null, 0, self::MAX_CELLS);
        $declaredCellCount = $this->boundedInteger($workbook['cellCount'] ?? null, 0, self::MAX_CELLS);
        $declaredUncachedFormulaCount = $this->boundedInteger(
            $workbook['formulasWithoutCachedResultCount'] ?? null,
            0,
            self::MAX_CELLS,
        );
        $declaredLinkCount = $this->boundedInteger($workbook['linkCount'] ?? null, 0, self::MAX_CELLS);
        $declaredMergeCount = $this->boundedInteger($workbook['mergeCount'] ?? null, 0, self::MAX_MERGES);
        $truncated = $workbook['truncated'] ?? null;

        if (
            $visibleSheetCount !== count($sheets)
            || $visibleSheetCount + $omittedHiddenSheetCount > self::MAX_SHEETS
            || $declaredUncachedFormulaCount > $declaredFormulaCount
            || $truncated !== false
        ) {
            throw new RuntimeException('XLSX workbook facts do not match its worksheets.');
        }

        /** @var array<string, array{rowExtent: int, columnExtent: int}> $sheetExtents */
        $sheetExtents = [];
        /** @var list<array{sheet: string, coordinate: string}> $internalLinks */
        $internalLinks = [];
        /** @var list<array<string, mixed>> $normalizedSheets */
        $normalizedSheets = [];
        $searchLines = [];
        $cellCount = 0;
        $formulaCount = 0;
        $formulasWithoutCachedResultCount = 0;
        $linkCount = 0;
        $mergeCount = 0;
        $projectedRowExtentCount = 0;
        $projectedColumnExtentCount = 0;
        $omittedHiddenRowCountTotal = 0;
        $omittedHiddenColumnCountTotal = 0;

        foreach ($sheets as $sheetValue) {
            $sheet = $this->object($sheetValue, 'XLSX worksheet has an invalid shape.');
            $this->assertExactKeys($sheet, [
                'cells',
                'columnExtent',
                'merges',
                'name',
                'omittedHiddenColumnCount',
                'omittedHiddenRowCount',
                'rowExtent',
            ]);

            $name = $sheet['name'] ?? null;
            $rowExtent = $this->boundedInteger($sheet['rowExtent'] ?? null, 0, self::MAX_ROWS);
            $columnExtent = $this->boundedInteger($sheet['columnExtent'] ?? null, 0, self::MAX_COLUMNS);
            $omittedHiddenRowCount = $this->boundedInteger(
                $sheet['omittedHiddenRowCount'] ?? null,
                0,
                self::MAX_ROWS,
            );
            $omittedHiddenColumnCount = $this->boundedInteger(
                $sheet['omittedHiddenColumnCount'] ?? null,
                0,
                self::MAX_COLUMNS,
            );
            $cells = $this->list($sheet['cells'] ?? null, 'XLSX worksheet cells have an invalid shape.');
            $merges = $this->list($sheet['merges'] ?? null, 'XLSX worksheet merges have an invalid shape.');

            $projectedRowExtentCount += $rowExtent;
            $projectedColumnExtentCount += $columnExtent;
            $omittedHiddenRowCountTotal += $omittedHiddenRowCount;
            $omittedHiddenColumnCountTotal += $omittedHiddenColumnCount;

            if (
                !is_string($name)
                || $name === ''
                || strlen($name) > self::MAX_SHEET_NAME_BYTES
                || !PageContentEncoding::isStorable($name)
                || array_key_exists($name, $sheetExtents)
            ) {
                throw new RuntimeException('XLSX worksheet contains invalid facts.');
            }

            $sheetExtents[$name] = [
                'rowExtent' => $rowExtent,
                'columnExtent' => $columnExtent,
            ];
            $previousRow = -1;
            $previousColumn = -1;
            /** @var list<array<string, mixed>> $normalizedCells */
            $normalizedCells = [];

            foreach ($cells as $cellValue) {
                $cell = $this->object($cellValue, 'XLSX cell has an invalid shape.');
                $this->assertAllowedKeys($cell, [
                    'cachedResultAvailable',
                    'coordinate',
                    'display',
                    'formula',
                    'kind',
                    'link',
                    'value',
                ]);

                foreach (['coordinate', 'kind', 'display', 'value'] as $requiredKey) {
                    if (!array_key_exists($requiredKey, $cell)) {
                        throw new RuntimeException('XLSX cell is missing a required field.');
                    }
                }

                [$row, $column] = $this->coordinate($cell['coordinate']);

                if (
                    $row >= $rowExtent
                    || $column >= $columnExtent
                    || $row < $previousRow
                    || ($row === $previousRow && $column <= $previousColumn)
                ) {
                    throw new RuntimeException('XLSX cell order or extent is invalid.');
                }

                $previousRow = $row;
                $previousColumn = $column;
                ++$cellCount;

                if ($cellCount > self::MAX_CELLS) {
                    throw new RuntimeException('XLSX manifest exceeds the cell limit.');
                }

                $normalizedCell = $this->validateCell($cell);
                $coordinate = $normalizedCell['coordinate'];
                $display = $normalizedCell['display'];

                if (!is_string($coordinate) || ($display !== null && !is_string($display))) {
                    throw new RuntimeException('XLSX normalized cell is invalid.');
                }

                if (is_string($display) && $display !== '') {
                    $searchLines[] = sprintf('[%s] %s %s', $name, $coordinate, $display);
                }

                if (array_key_exists('formula', $normalizedCell)) {
                    ++$formulaCount;
                    $formula = $normalizedCell['formula'];

                    if (!is_string($formula)) {
                        throw new RuntimeException('XLSX normalized formula is invalid.');
                    }

                    $searchLines[] = sprintf('[%s] %s =%s', $name, $coordinate, $formula);

                    if (($normalizedCell['cachedResultAvailable'] ?? null) === false) {
                        ++$formulasWithoutCachedResultCount;
                    }
                }

                if (array_key_exists('link', $normalizedCell)) {
                    ++$linkCount;
                    $link = $normalizedCell['link'];
                    assert(is_array($link));

                    if (($link['kind'] ?? null) === 'internal') {
                        $targetSheet = $link['sheet'] ?? null;
                        $targetCoordinate = $link['coordinate'] ?? null;
                        assert(is_string($targetSheet));
                        assert(is_string($targetCoordinate));
                        $internalLinks[] = ['sheet' => $targetSheet, 'coordinate' => $targetCoordinate];
                    }
                }

                $normalizedCells[] = $normalizedCell;
            }

            if (count($merges) > self::MAX_MERGES) {
                throw new RuntimeException('XLSX worksheet exceeds the merged-range limit.');
            }

            /** @var list<array{start: string, end: string, startRow: int, startColumn: int, endRow: int, endColumn: int}> $acceptedMerges */
            $acceptedMerges = [];
            /** @var list<array{start: string, end: string}> $normalizedMerges */
            $normalizedMerges = [];

            foreach ($merges as $mergeValue) {
                $merge = $this->object($mergeValue, 'XLSX merged range has an invalid shape.');
                $this->assertExactKeys($merge, ['end', 'start']);
                $start = $merge['start'] ?? null;
                $end = $merge['end'] ?? null;

                if (!is_string($start) || !is_string($end)) {
                    throw new RuntimeException('XLSX merged range has invalid coordinates.');
                }

                [$startRow, $startColumn] = $this->coordinate($start);
                [$endRow, $endColumn] = $this->coordinate($end);

                if (
                    $startRow > $endRow
                    || $startColumn > $endColumn
                    || $endRow >= $rowExtent
                    || $endColumn >= $columnExtent
                ) {
                    throw new RuntimeException('XLSX merged range is outside the visible extent.');
                }

                foreach ($acceptedMerges as $accepted) {
                    $separated = $endRow < $accepted['startRow']
                        || $startRow > $accepted['endRow']
                        || $endColumn < $accepted['startColumn']
                        || $startColumn > $accepted['endColumn'];

                    if (!$separated) {
                        throw new RuntimeException('XLSX merged ranges overlap.');
                    }
                }

                $acceptedMerges[] = [
                    'start' => $start,
                    'end' => $end,
                    'startRow' => $startRow,
                    'startColumn' => $startColumn,
                    'endRow' => $endRow,
                    'endColumn' => $endColumn,
                ];
                $normalizedMerges[] = ['start' => $start, 'end' => $end];
                ++$mergeCount;

                if ($mergeCount > self::MAX_MERGES) {
                    throw new RuntimeException('XLSX manifest exceeds the merged-range limit.');
                }
            }

            $normalizedSheets[] = [
                'name' => $name,
                'rowExtent' => $rowExtent,
                'columnExtent' => $columnExtent,
                'omittedHiddenRowCount' => $omittedHiddenRowCount,
                'omittedHiddenColumnCount' => $omittedHiddenColumnCount,
                'merges' => $normalizedMerges,
                'cells' => $normalizedCells,
            ];
        }

        foreach ($internalLinks as $link) {
            $extent = $sheetExtents[$link['sheet']] ?? null;

            if ($extent === null) {
                throw new RuntimeException('XLSX internal hyperlink targets an unavailable worksheet.');
            }

            [$row, $column] = $this->coordinate($link['coordinate']);

            if ($row >= $extent['rowExtent'] || $column >= $extent['columnExtent']) {
                throw new RuntimeException('XLSX internal hyperlink target is outside the visible extent.');
            }
        }

        if (
            $cellCount !== $declaredCellCount
            || $formulaCount !== $declaredFormulaCount
            || $formulasWithoutCachedResultCount !== $declaredUncachedFormulaCount
            || $linkCount !== $declaredLinkCount
            || $mergeCount !== $declaredMergeCount
        ) {
            throw new RuntimeException('XLSX workbook counts do not match its cells.');
        }

        $reconstructedSearchText = implode("\n", $searchLines);

        if (!hash_equals($reconstructedSearchText, $searchText)) {
            throw new RuntimeException('XLSX search projection does not match its visible cells.');
        }

        $normalizedManifest = [
            'schema' => 'xlsx-view-manifest-v1',
            'profile' => XlsxProcessorProtocol::PROCESSOR_PROFILE,
            'workbook' => [
                'visibleSheetCount' => $visibleSheetCount,
                'omittedHiddenSheetCount' => $omittedHiddenSheetCount,
                'cellCount' => $cellCount,
                'formulaCount' => $formulaCount,
                'formulasWithoutCachedResultCount' => $formulasWithoutCachedResultCount,
                'linkCount' => $linkCount,
                'mergeCount' => $mergeCount,
                'truncated' => false,
            ],
            'sheets' => $normalizedSheets,
            'searchText' => $reconstructedSearchText,
        ];

        try {
            $manifestJson = json_encode(
                $normalizedManifest,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('XLSX manifest could not be canonicalized.', previous: $exception);
        }

        if (strlen($manifestJson) > XlsxProcessorConfiguration::MAX_MANIFEST_BYTES) {
            throw new RuntimeException('XLSX manifest exceeds its byte limit.');
        }

        return new XlsxManifestValidationResult(
            manifestJson: $manifestJson,
            searchText: $reconstructedSearchText,
            visibleSheetCount: $visibleSheetCount,
            omittedHiddenSheetCount: $omittedHiddenSheetCount,
            projectedRowExtentCount: $projectedRowExtentCount,
            projectedColumnExtentCount: $projectedColumnExtentCount,
            omittedHiddenRowCount: $omittedHiddenRowCountTotal,
            omittedHiddenColumnCount: $omittedHiddenColumnCountTotal,
            cellCount: $cellCount,
            formulaCount: $formulaCount,
            formulasWithoutCachedResultCount: $formulasWithoutCachedResultCount,
            linkCount: $linkCount,
            mergeCount: $mergeCount,
            truncated: false,
        );
    }

    /**
     * @param array<string, mixed> $cell
     * @return array<string, mixed>
     */
    private function validateCell(array $cell): array
    {
        $coordinate = $cell['coordinate'];
        $kind = $cell['kind'];
        $display = $cell['display'];
        $value = $cell['value'];

        if (!is_string($coordinate) || !is_string($kind)) {
            throw new RuntimeException('XLSX cell identity is invalid.');
        }

        if (
            ($display !== null && !is_string($display))
            || (is_string($display) && !$this->boundedStorableString($display, self::MAX_STRING_BYTES))
            || !$this->valueMatchesKind($kind, $value)
            || (is_string($value) && !$this->boundedStorableString($value, self::MAX_STRING_BYTES))
        ) {
            throw new RuntimeException('XLSX cell value is invalid.');
        }

        $hasFormula = array_key_exists('formula', $cell);
        $hasCachedFact = array_key_exists('cachedResultAvailable', $cell);

        if ($hasFormula !== $hasCachedFact) {
            throw new RuntimeException('XLSX formula facts are incomplete.');
        }

        $normalized = [
            'coordinate' => $coordinate,
            'kind' => $kind,
            'display' => $display,
            'value' => $value,
        ];

        if ($hasFormula) {
            $formula = $cell['formula'];
            $cachedResultAvailable = $cell['cachedResultAvailable'];

            if (
                !is_string($formula)
                || !$this->boundedStorableString($formula, self::MAX_FORMULA_BYTES)
                || !is_bool($cachedResultAvailable)
                || (!$cachedResultAvailable && ($kind !== 'formula' || $value !== null || $display !== null))
                || ($cachedResultAvailable && $kind === 'formula')
            ) {
                throw new RuntimeException('XLSX formula cell is invalid.');
            }

            $normalized['formula'] = $formula;
            $normalized['cachedResultAvailable'] = $cachedResultAvailable;
        } elseif ($kind === 'formula') {
            throw new RuntimeException('XLSX formula cell is missing formula facts.');
        }

        if (array_key_exists('link', $cell)) {
            $normalized['link'] = $this->validateLink($cell['link']);
        }

        return $normalized;
    }

    private function valueMatchesKind(string $kind, mixed $value): bool
    {
        return match ($kind) {
            'blank', 'formula' => $value === null,
            'boolean' => is_bool($value),
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            'date', 'error', 'string' => is_string($value),
            default => false,
        };
    }

    /**
     * @return array<string, string>
     */
    private function validateLink(mixed $linkValue): array
    {
        $link = $this->object($linkValue, 'XLSX hyperlink has an invalid shape.');
        $kind = $link['kind'] ?? null;

        if ($kind === 'internal') {
            $this->assertExactKeys($link, ['coordinate', 'kind', 'sheet']);
            $sheet = $link['sheet'] ?? null;
            $coordinate = $link['coordinate'] ?? null;

            if (
                !is_string($sheet)
                || $sheet === ''
                || strlen($sheet) > self::MAX_SHEET_NAME_BYTES
                || !PageContentEncoding::isStorable($sheet)
                || !is_string($coordinate)
            ) {
                throw new RuntimeException('XLSX internal hyperlink is invalid.');
            }

            $this->coordinate($coordinate);

            return ['kind' => 'internal', 'sheet' => $sheet, 'coordinate' => $coordinate];
        }

        if ($kind === 'external') {
            $this->assertExactKeys($link, ['kind', 'target']);
            $target = $link['target'] ?? null;

            if (!is_string($target) || !$this->safeExternalTarget($target)) {
                throw new RuntimeException('XLSX external hyperlink is invalid.');
            }

            return ['kind' => 'external', 'target' => $target];
        }

        throw new RuntimeException('XLSX hyperlink kind is invalid.');
    }

    private function safeExternalTarget(string $target): bool
    {
        if (
            $target === ''
            || strlen($target) > self::MAX_LINK_BYTES
            || !PageContentEncoding::isStorable($target)
            || str_contains($target, "\x7f")
            || preg_match('/\s/u', $target) === 1
        ) {
            return false;
        }

        $parts = parse_url($target);

        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = $parts['scheme'] ?? null;

        if ($scheme === 'mailto') {
            return str_starts_with($target, 'mailto:') && !str_starts_with($target, 'mailto://');
        }

        return ($scheme === 'http' || $scheme === 'https')
            && isset($parts['host'])
            && $parts['host'] !== ''
            && str_starts_with($target, $scheme . '://');
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function coordinate(mixed $coordinate): array
    {
        if (!is_string($coordinate) || preg_match('/\A([A-Z]{1,3})([1-9][0-9]{0,4})\z/', $coordinate, $matches) !== 1) {
            throw new RuntimeException('XLSX cell coordinate is invalid.');
        }

        $column = 0;

        foreach (str_split($matches[1]) as $character) {
            $column = ($column * 26) + ord($character) - 64;
        }

        --$column;
        $row = ((int) $matches[2]) - 1;

        if ($row >= self::MAX_ROWS || $column >= self::MAX_COLUMNS) {
            throw new RuntimeException('XLSX cell coordinate exceeds the accepted profile.');
        }

        return [$row, $column];
    }

    private function boundedStorableString(string $value, int $maximumBytes): bool
    {
        return strlen($value) <= $maximumBytes && PageContentEncoding::isStorable($value);
    }

    private function boundedInteger(mixed $value, int $minimum, int $maximum): int
    {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new RuntimeException('XLSX manifest contains an invalid integer fact.');
        }

        return $value;
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $expected
     */
    private function assertExactKeys(array $value, array $expected): void
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);

        if ($keys !== $expected) {
            throw new RuntimeException('XLSX manifest contains unexpected fields.');
        }
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $allowed
     */
    private function assertAllowedKeys(array $value, array $allowed): void
    {
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new RuntimeException('XLSX manifest contains unexpected fields.');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function object(mixed $value, string $message): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException($message);
        }

        /** @var array<string, mixed> $object */
        $object = [];

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new RuntimeException($message);
            }

            $object[$key] = $item;
        }

        return $object;
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value, string $message): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException($message);
        }

        return $value;
    }
}
