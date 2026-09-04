<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;
use App\Domain\DomainRuleViolation;
use JsonException;
use RuntimeException;

/** A caller-selected, response-bounded slice of one visible worksheet. */
final readonly class McpXlsxSelectionView implements McpWirePayload
{
    private const int MAX_SELECTED_CELLS = 1_000;
    private const int MAX_WIRE_BYTES = 2 * 1024 * 1024;

    /** @param array<string, mixed> $wire */
    private function __construct(private array $wire)
    {
    }

    public static function fromJson(string $json, string $sheetName, string $range): self
    {
        try {
            $manifest = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Stored XLSX manifest is invalid.', previous: $exception);
        }

        if (!is_array($manifest) || array_is_list($manifest)) {
            throw new RuntimeException('Stored XLSX manifest has an invalid shape.');
        }

        [$startRow, $startColumn, $endRow, $endColumn] = self::range($range);
        $area = ($endRow - $startRow + 1) * ($endColumn - $startColumn + 1);

        if ($area > self::MAX_SELECTED_CELLS) {
            throw new DomainRuleViolation(sprintf(
                'Argument [xlsx_range] must select at most %d cells.',
                self::MAX_SELECTED_CELLS,
            ));
        }

        $sheets = $manifest['sheets'] ?? null;

        if (!is_array($sheets) || !array_is_list($sheets)) {
            throw new RuntimeException('Stored XLSX manifest has invalid worksheets.');
        }

        $selectedSheet = null;

        foreach ($sheets as $candidate) {
            if (!is_array($candidate) || array_is_list($candidate)) {
                throw new RuntimeException('Stored XLSX manifest has an invalid worksheet.');
            }

            if (($candidate['name'] ?? null) === $sheetName) {
                $selectedSheet = $candidate;
                break;
            }
        }

        if (!is_array($selectedSheet)) {
            throw new DomainRuleViolation('Argument [xlsx_sheet] does not name a visible worksheet.');
        }

        $cells = self::list($selectedSheet['cells'] ?? null, 'Stored XLSX worksheet has invalid cells.');
        $selectedCells = [];

        foreach ($cells as $cell) {
            $object = self::object($cell, 'Stored XLSX worksheet has an invalid cell.');
            [$row, $column] = self::coordinate($object['coordinate'] ?? null);

            if ($row >= $startRow && $row <= $endRow && $column >= $startColumn && $column <= $endColumn) {
                $selectedCells[] = self::wrap($object);
            }
        }

        $merges = self::list($selectedSheet['merges'] ?? null, 'Stored XLSX worksheet has invalid merges.');
        $selectedMerges = [];

        foreach ($merges as $merge) {
            $object = self::object($merge, 'Stored XLSX worksheet has an invalid merge.');
            [$mergeStartRow, $mergeStartColumn] = self::coordinate($object['start'] ?? null);
            [$mergeEndRow, $mergeEndColumn] = self::coordinate($object['end'] ?? null);

            if (
                $mergeStartRow >= $startRow
                && $mergeStartColumn >= $startColumn
                && $mergeEndRow <= $endRow
                && $mergeEndColumn <= $endColumn
            ) {
                $selectedMerges[] = self::wrap($object);
            }
        }

        $wire = [
            'schema' => 'artifactflow-mcp-xlsx-selection-v1',
            'sheet' => (new McpUntrustedText($sheetName))->toWire(),
            'range' => $range,
            'row_start' => $startRow + 1,
            'row_end' => $endRow + 1,
            'column_start' => $startColumn + 1,
            'column_end' => $endColumn + 1,
            'sheet_row_extent' => self::integer($selectedSheet['rowExtent'] ?? null),
            'sheet_column_extent' => self::integer($selectedSheet['columnExtent'] ?? null),
            'sheet_cell_count' => count($cells),
            'selected_cell_count' => count($selectedCells),
            'has_cells_outside_range' => count($selectedCells) < count($cells),
            'sheet_merge_count' => count($merges),
            'selected_merge_count' => count($selectedMerges),
            'has_merges_outside_range' => count($selectedMerges) < count($merges),
            'cells' => $selectedCells,
            'merges' => $selectedMerges,
        ];

        try {
            $encoded = json_encode($wire, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new RuntimeException('Selected XLSX worksheet data is invalid.', previous: $exception);
        }

        if (strlen($encoded) > self::MAX_WIRE_BYTES) {
            throw new DomainRuleViolation('Selected XLSX range exceeds the MCP response limit; request a narrower range.');
        }

        return new self($wire);
    }

    public function toWire(): array
    {
        return $this->wire;
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} */
    private static function range(string $range): array
    {
        if (preg_match('/\A([A-Z]{1,3}[1-9][0-9]{0,4}):([A-Z]{1,3}[1-9][0-9]{0,4})\z/D', $range, $matches) !== 1) {
            throw new DomainRuleViolation('Argument [xlsx_range] must be a canonical uppercase range such as A1:F50.');
        }

        [$startRow, $startColumn] = self::coordinate($matches[1]);
        [$endRow, $endColumn] = self::coordinate($matches[2]);

        if ($startRow > $endRow || $startColumn > $endColumn) {
            throw new DomainRuleViolation('Argument [xlsx_range] must run from its upper-left to lower-right cell.');
        }

        return [$startRow, $startColumn, $endRow, $endColumn];
    }

    /** @return array{0: int, 1: int} */
    private static function coordinate(mixed $coordinate): array
    {
        if (!is_string($coordinate) || preg_match('/\A([A-Z]{1,3})([1-9][0-9]{0,4})\z/D', $coordinate, $matches) !== 1) {
            throw new DomainRuleViolation('XLSX cell coordinate is outside the supported MCP selection profile.');
        }

        $column = 0;

        foreach (str_split($matches[1]) as $character) {
            $column = ($column * 26) + ord($character) - 64;
        }

        $row = ((int) $matches[2]) - 1;
        --$column;

        if ($row >= 20_000 || $column >= 256) {
            throw new DomainRuleViolation('XLSX cell coordinate is outside the supported MCP selection profile.');
        }

        return [$row, $column];
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value, string $message): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException($message);
        }

        $object = [];

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new RuntimeException($message);
            }

            $object[$key] = $item;
        }

        return $object;
    }

    /** @return list<mixed> */
    private static function list(mixed $value, string $message): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException($message);
        }

        return $value;
    }

    private static function integer(mixed $value): int
    {
        if (!is_int($value) || $value < 0) {
            throw new RuntimeException('Stored XLSX worksheet has an invalid extent.');
        }

        return $value;
    }

    private static function wrap(mixed $value): mixed
    {
        if (is_string($value)) {
            return (new McpUntrustedText($value))->toWire();
        }

        if (!is_array($value)) {
            return $value;
        }

        $wrapped = [];

        foreach ($value as $key => $item) {
            $wrapped[$key] = self::wrap($item);
        }

        return $wrapped;
    }
}
