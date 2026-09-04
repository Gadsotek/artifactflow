<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use App\Application\Mcp\Output\McpXlsxSelectionView;
use Tests\TestCase;
use Throwable;

final class McpXlsxSelectionViewTest extends TestCase
{
    public function test_selection_bounds_cells_merges_and_untrusted_nested_text(): void
    {
        $selection = McpXlsxSelectionView::fromJson(
            $this->encode($this->manifest()),
            'Visible',
            'B2:C3',
        )->toWire();

        $this->assertSame('artifactflow-mcp-xlsx-selection-v1', $selection['schema']);
        $this->assertSame('Visible', data_get($selection, 'sheet.data'));
        $this->assertSame([2, 3, 2, 3], [
            $selection['row_start'],
            $selection['row_end'],
            $selection['column_start'],
            $selection['column_end'],
        ]);
        $this->assertSame(3, $selection['sheet_cell_count']);
        $this->assertSame(2, $selection['selected_cell_count']);
        $this->assertTrue($selection['has_cells_outside_range']);
        $this->assertSame(2, $selection['sheet_merge_count']);
        $this->assertSame(1, $selection['selected_merge_count']);
        $this->assertTrue($selection['has_merges_outside_range']);
        $this->assertSame('B2', data_get($selection, 'cells.0.coordinate.data'));
        $this->assertSame('https://example.test/report', data_get($selection, 'cells.0.link.target.data'));
        $this->assertSame(true, data_get($selection, 'cells.1.value'));
        $this->assertSame('B2', data_get($selection, 'merges.0.start.data'));
    }

    public function test_selection_rejects_malformed_stored_manifests_and_ranges(): void
    {
        $valid = $this->manifest();

        foreach ([
            ['{', 'Visible', 'A1:A1'],
            ['[]', 'Visible', 'A1:A1'],
            [$this->encode($valid), 'Visible', 'a1:A1'],
            [$this->encode($valid), 'Visible', 'B2:A1'],
            [$this->encode($valid), 'Visible', 'A1:Z100'],
            [$this->encode($valid), 'Hidden', 'A1:A1'],
        ] as [$json, $sheet, $range]) {
            $this->assertRejected($json, $sheet, $range);
        }

        foreach ([
            static function (array &$manifest): void {
                $manifest['sheets'] = ['bad' => 'shape'];
            },
            static function (array &$manifest): void {
                data_set($manifest, 'sheets.0', 'bad worksheet');
            },
            static function (array &$manifest): void {
                data_set($manifest, 'sheets.0.cells', ['bad' => 'shape']);
            },
            static function (array &$manifest): void {
                data_set($manifest, 'sheets.0.cells.0', 'bad cell');
            },
            static function (array &$manifest): void {
                data_set($manifest, 'sheets.0.cells.0.coordinate', 'XFE1');
            },
            static function (array &$manifest): void {
                data_set($manifest, 'sheets.0.merges', ['bad' => 'shape']);
            },
            static function (array &$manifest): void {
                data_set($manifest, 'sheets.0.merges.0', 'bad merge');
            },
            static function (array &$manifest): void {
                data_set($manifest, 'sheets.0.rowExtent', -1);
            },
        ] as $mutate) {
            $manifest = $valid;
            $mutate($manifest);
            $this->assertRejected($this->encode($manifest), 'Visible', 'A1:C3');
        }
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return [
            'sheets' => [[
                'name' => 'Visible',
                'rowExtent' => 3,
                'columnExtent' => 3,
                'cells' => [
                    ['coordinate' => 'A1', 'kind' => 'string', 'display' => 'Outside', 'value' => 'Outside'],
                    [
                        'coordinate' => 'B2',
                        'kind' => 'string',
                        'display' => 'Report',
                        'value' => 'Report',
                        'link' => ['kind' => 'external', 'target' => 'https://example.test/report'],
                    ],
                    ['coordinate' => 'C3', 'kind' => 'boolean', 'display' => 'TRUE', 'value' => true],
                ],
                'merges' => [
                    ['start' => 'A1', 'end' => 'A2'],
                    ['start' => 'B2', 'end' => 'C3'],
                ],
            ]],
        ];
    }

    private function assertRejected(string $json, string $sheet, string $range): void
    {
        try {
            McpXlsxSelectionView::fromJson($json, $sheet, $range);
            $this->fail('Malformed XLSX selection input must be rejected.');
        } catch (Throwable) {
            $this->addToAssertionCount(1);
        }
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
