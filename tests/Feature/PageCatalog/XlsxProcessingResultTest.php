<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\PageCatalog\XlsxProcessingResult;
use RuntimeException;
use Tests\TestCase;

final class XlsxProcessingResultTest extends TestCase
{
    public function test_it_accepts_and_canonicalizes_the_exact_bounded_manifest_schema(): void
    {
        $input = 'xlsx bytes';
        $result = XlsxProcessingResult::fromJson(
            $this->validResponse($input),
            strlen($input),
            hash('sha256', $input),
        );

        $this->assertSame('xlsx-processor-response-v1', $result->responseSchema);
        $this->assertSame('xlsx-typed-view-v1', $result->processorProfile);
        $this->assertSame('sheetjs-ce', $result->engineName);
        $this->assertSame('0.20.3', $result->engineVersion);
        $this->assertSame(9, $result->packageEntryCount);
        $this->assertSame(2_048, $result->expandedBytes);
        $this->assertSame(1, $result->visibleSheetCount);
        $this->assertSame(1, $result->omittedHiddenSheetCount);
        $this->assertSame(1, $result->formulaCount);
        $this->assertSame(0, $result->formulasWithoutCachedResultCount);
        $this->assertSame(1, $result->linkCount);
        $this->assertSame(0, $result->mergeCount);
        $this->assertSame(2, $result->cellCount);
        $this->assertFalse($result->truncated);
        $this->assertSame(
            "[Summary] A1 Open report\n[Summary] B1 42\n[Summary] B1 =SUM(B2:B4)",
            $result->searchText,
        );
        $this->assertSame('xlsx-view-manifest-v1', data_get(json_decode(
            $result->manifestJson,
            true,
            flags: JSON_THROW_ON_ERROR,
        ), 'schema'));
    }

    public function test_it_accepts_every_supported_typed_cell_link_and_merge_form(): void
    {
        $input = 'xlsx bytes';
        $result = XlsxProcessingResult::fromJson(
            $this->encode($this->complexResponseArray($input)),
            strlen($input),
            hash('sha256', $input),
        );

        $this->assertSame(4, $result->projectedRowExtentCount);
        $this->assertSame(4, $result->projectedColumnExtentCount);
        $this->assertSame(2, $result->omittedHiddenRowCount);
        $this->assertSame(1, $result->omittedHiddenColumnCount);
        $this->assertSame(9, $result->cellCount);
        $this->assertSame(1, $result->formulaCount);
        $this->assertSame(1, $result->formulasWithoutCachedResultCount);
        $this->assertSame(3, $result->linkCount);
        $this->assertSame(2, $result->mergeCount);
        $this->assertSame(
            "[Summary] A1 Open report\n"
            . "[Summary] B1 TRUE\n"
            . "[Summary] C1 2026-08-31\n"
            . "[Summary] D1 #N/A\n"
            . "[Summary] B2 =NOW()\n"
            . "[Summary] C2 Mail support\n"
            . "[Summary] D2 3.14\n"
            . '[Summary] D4 Target',
            $result->searchText,
        );

        $manifest = json_decode($result->manifestJson, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(
            [
                ['start' => 'A3', 'end' => 'B3'],
                ['start' => 'C3', 'end' => 'D3'],
            ],
            data_get($manifest, 'sheets.0.merges'),
        );
        $this->assertSame(
            ['kind' => 'internal', 'sheet' => 'Summary', 'coordinate' => 'D4'],
            data_get($manifest, 'sheets.0.cells.0.link'),
        );
        $this->assertSame(
            ['kind' => 'external', 'target' => 'mailto:support@example.com'],
            data_get($manifest, 'sheets.0.cells.6.link'),
        );
    }

    public function test_it_rejects_malformed_response_envelopes(): void
    {
        $input = 'xlsx bytes';

        foreach ([
            '{',
            '[]',
            $this->encode(['unexpected' => true]),
        ] as $response) {
            $this->assertRejected($response, $input);
        }

        foreach ([
            static function (array &$response): void {
                $response['engine'] = [];
            },
            static function (array &$response): void {
                $response['input'] = [];
            },
            static function (array &$response): void {
                $response['package'] = [];
            },
            static function (array &$response): void {
                $response['manifest'] = [];
            },
            static function (array &$response): void {
                data_set($response, 'engine.version', '0.0.0');
            },
            static function (array &$response): void {
                data_set($response, 'input.bytes', '10');
            },
            static function (array &$response): void {
                data_set($response, 'package.entryCount', 0);
            },
            static function (array &$response): void {
                data_set($response, 'package.expandedBytes', 0);
            },
        ] as $mutate) {
            $response = $this->validResponseArray($input);
            $mutate($response);
            $this->assertRejected($this->encode($response), $input);
        }

        $this->assertRejected($this->validResponse($input), $input, 'not-a-sha256');
    }

    public function test_it_rejects_malformed_manifest_shapes_and_facts(): void
    {
        $input = 'xlsx bytes';

        foreach ([
            static function (array &$response): void {
                data_set($response, 'manifest.schema', 'unknown');
            },
            static function (array &$response): void {
                data_set($response, 'manifest.profile', 'unknown');
            },
            static function (array &$response): void {
                data_set($response, 'manifest.workbook.truncated', true);
            },
            static function (array &$response): void {
                data_set($response, 'manifest.workbook.visibleSheetCount', 2);
            },
            static function (array &$response): void {
                data_set($response, 'manifest.workbook.omittedHiddenSheetCount', -1);
            },
            static function (array &$response): void {
                data_set($response, 'manifest.workbook.formulasWithoutCachedResultCount', 2);
            },
            static function (array &$response): void {
                data_set($response, 'manifest.sheets', []);
                data_set($response, 'manifest.workbook.visibleSheetCount', 0);
                data_set($response, 'manifest.workbook.cellCount', 0);
                data_set($response, 'manifest.workbook.formulaCount', 0);
                data_set($response, 'manifest.workbook.linkCount', 0);
                data_set($response, 'manifest.searchText', '');
            },
            static function (array &$response): void {
                data_set($response, 'manifest.sheets.0.name', '');
            },
            static function (array &$response): void {
                data_set($response, 'manifest.sheets.0.cells', ['not-an-object']);
            },
            static function (array &$response): void {
                data_set($response, 'manifest.sheets.0.merges', ['not-an-object']);
            },
            static function (array &$response): void {
                data_set($response, 'manifest.sheets.0.rowExtent', '1');
            },
            static function (array &$response): void {
                data_set($response, 'manifest.searchText', 42);
            },
        ] as $mutate) {
            $response = $this->validResponseArray($input);
            $mutate($response);
            $this->assertRejected($this->encode($response), $input);
        }
    }

    public function test_it_rejects_invalid_typed_cells_formulas_and_links(): void
    {
        $input = 'xlsx bytes';

        foreach ([
            static function (array &$response): void {
                data_forget($response, 'manifest.sheets.0.cells.0.display');
            },
            static function (array &$response): void {
                data_set($response, 'manifest.sheets.0.cells.0.unexpected', true);
            },
            static function (array &$response): void {
                data_set($response, 'manifest.sheets.0.cells.0.coordinate', 'a1');
            },
            static function (array &$response): void {
                data_set($response, 'manifest.sheets.0.cells.0.coordinate', 'XFE1');
            },
            static function (array &$response): void {
                data_set($response, 'manifest.sheets.0.cells.0.kind', 'unknown');
            },
            static function (array &$response): void {
                data_set($response, 'manifest.sheets.0.cells.0.display', 42);
            },
            static function (array &$response): void {
                data_set($response, 'manifest.sheets.0.cells.1.value', 'not-a-number');
            },
            static function (array &$response): void {
                data_forget($response, 'manifest.sheets.0.cells.1.cachedResultAvailable');
            },
            static function (array &$response): void {
                data_set($response, 'manifest.sheets.0.cells.1.cachedResultAvailable', false);
            },
            static function (array &$response): void {
                data_set($response, 'manifest.sheets.0.cells.1.kind', 'formula');
            },
            static function (array &$response): void {
                data_forget($response, 'manifest.sheets.0.cells.1.formula');
                data_forget($response, 'manifest.sheets.0.cells.1.cachedResultAvailable');
                data_set($response, 'manifest.sheets.0.cells.1.kind', 'formula');
                data_set($response, 'manifest.sheets.0.cells.1.value', null);
                data_set($response, 'manifest.sheets.0.cells.1.display', null);
            },
            static function (array &$response): void {
                data_set($response, 'manifest.sheets.0.cells.0.link', []);
            },
            static function (array &$response): void {
                data_set($response, 'manifest.sheets.0.cells.0.link.target', 'https://user:pass@example.com');
            },
            static function (array &$response): void {
                data_set($response, 'manifest.sheets.0.cells.0.link.target', 'https://example.com/a b');
            },
            static function (array &$response): void {
                data_set($response, 'manifest.sheets.0.cells.0.link.target', 'mailto://support@example.com');
            },
            static function (array &$response): void {
                data_set($response, 'manifest.sheets.0.cells.0.link.kind', 'unknown');
            },
        ] as $mutate) {
            $response = $this->validResponseArray($input);
            $mutate($response);
            $this->assertRejected($this->encode($response), $input);
        }
    }

    public function test_it_rejects_invalid_or_overlapping_merged_ranges(): void
    {
        $input = 'xlsx bytes';

        foreach ([
            static function (array &$response): void {
                data_set($response, 'manifest.sheets.0.merges.0', ['start' => 'A1', 'end' => 'C1']);
                data_set($response, 'manifest.workbook.mergeCount', 1);
            },
            static function (array &$response): void {
                data_set($response, 'manifest.sheets.0.merges.0', ['start' => 'B1', 'end' => 'A1']);
                data_set($response, 'manifest.workbook.mergeCount', 1);
            },
            static function (array &$response): void {
                data_set($response, 'manifest.sheets.0.merges.0', ['start' => 1, 'end' => 'B1']);
                data_set($response, 'manifest.workbook.mergeCount', 1);
            },
        ] as $mutate) {
            $response = $this->validResponseArray($input);
            $mutate($response);
            $this->assertRejected($this->encode($response), $input);
        }

        $response = $this->complexResponseArray($input);
        data_set($response, 'manifest.sheets.0.merges.1', ['start' => 'B3', 'end' => 'C3']);
        $this->assertRejected($this->encode($response), $input);

        $response = $this->complexResponseArray($input);
        data_set($response, 'manifest.sheets.0.cells.0.link.sheet', 'Unavailable');
        $this->assertRejected($this->encode($response), $input);
    }

    public function test_it_rejects_unknown_authority_and_input_or_fact_mismatch(): void
    {
        $input = 'xlsx bytes';

        foreach ([
            static function (array &$response): void {
                $response['renderer'] = 'workbook-controlled';
            },
            static function (array &$response): void {
                data_set($response, 'input.sha256', str_repeat('0', 64));
            },
            static function (array &$response): void {
                data_set($response, 'manifest.workbook.formulaCount', 0);
            },
            static function (array &$response): void {
                data_set($response, 'manifest.sheets.0.cells.1.value', '42');
            },
            static function (array &$response): void {
                $searchText = data_get($response, 'manifest.searchText');

                if (!is_string($searchText)) {
                    throw new RuntimeException('Fixture search text is invalid.');
                }

                data_set(
                    $response,
                    'manifest.searchText',
                    $searchText . "\n[Hidden] A1 secret",
                );
            },
            static function (array &$response): void {
                data_set($response, 'manifest.sheets.0.cells.0.link.target', 'javascript:alert(1)');
            },
        ] as $mutate) {
            $response = $this->validResponseArray($input);
            $mutate($response);

            try {
                XlsxProcessingResult::fromJson(
                    $this->encode($response),
                    strlen($input),
                    hash('sha256', $input),
                );
                $this->fail('Malformed XLSX processor authority must be rejected.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_rejects_internal_links_outside_the_validated_visible_extent(): void
    {
        $input = 'xlsx bytes';
        $response = $this->validResponseArray($input);
        data_set($response, 'manifest.workbook.linkCount', 2);
        data_set($response, 'manifest.sheets.0.cells.1.link', [
            'kind' => 'internal',
            'sheet' => 'Summary',
            'coordinate' => 'A2',
        ]);

        $this->expectException(RuntimeException::class);

        XlsxProcessingResult::fromJson(
            $this->encode($response),
            strlen($input),
            hash('sha256', $input),
        );
    }

    public function test_it_rejects_non_canonical_cell_order_and_oversized_package_facts(): void
    {
        $input = 'xlsx bytes';
        $response = $this->validResponseArray($input);
        $cells = data_get($response, 'manifest.sheets.0.cells');
        $this->assertIsArray($cells);
        data_set($response, 'manifest.sheets.0.cells', array_reverse($cells));

        try {
            XlsxProcessingResult::fromJson(
                $this->encode($response),
                strlen($input),
                hash('sha256', $input),
            );
            $this->fail('Non-canonical cell order must be rejected.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $response = $this->validResponseArray($input);
        data_set($response, 'package.expandedBytes', (64 * 1024 * 1024) + 1);

        $this->expectException(RuntimeException::class);

        XlsxProcessingResult::fromJson(
            $this->encode($response),
            strlen($input),
            hash('sha256', $input),
        );
    }

    private function validResponse(string $input): string
    {
        return $this->encode($this->validResponseArray($input));
    }

    /**
     * @return array<string, mixed>
     */
    private function validResponseArray(string $input): array
    {
        return [
            'schema' => 'xlsx-processor-response-v1',
            'profile' => 'xlsx-typed-view-v1',
            'engine' => ['name' => 'sheetjs-ce', 'version' => '0.20.3'],
            'input' => ['bytes' => strlen($input), 'sha256' => hash('sha256', $input)],
            'package' => ['entryCount' => 9, 'expandedBytes' => 2_048],
            'manifest' => [
                'schema' => 'xlsx-view-manifest-v1',
                'profile' => 'xlsx-typed-view-v1',
                'workbook' => [
                    'visibleSheetCount' => 1,
                    'omittedHiddenSheetCount' => 1,
                    'cellCount' => 2,
                    'formulaCount' => 1,
                    'formulasWithoutCachedResultCount' => 0,
                    'linkCount' => 1,
                    'mergeCount' => 0,
                    'truncated' => false,
                ],
                'sheets' => [[
                    'name' => 'Summary',
                    'rowExtent' => 1,
                    'columnExtent' => 2,
                    'omittedHiddenRowCount' => 0,
                    'omittedHiddenColumnCount' => 0,
                    'merges' => [],
                    'cells' => [
                        [
                            'coordinate' => 'A1',
                            'kind' => 'string',
                            'display' => 'Open report',
                            'value' => 'Open report',
                            'link' => [
                                'kind' => 'external',
                                'target' => 'https://example.com/report',
                            ],
                        ],
                        [
                            'coordinate' => 'B1',
                            'kind' => 'number',
                            'display' => '42',
                            'value' => 42,
                            'formula' => 'SUM(B2:B4)',
                            'cachedResultAvailable' => true,
                        ],
                    ],
                ]],
                'searchText' => "[Summary] A1 Open report\n[Summary] B1 42\n[Summary] B1 =SUM(B2:B4)",
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function complexResponseArray(string $input): array
    {
        return [
            'schema' => 'xlsx-processor-response-v1',
            'profile' => 'xlsx-typed-view-v1',
            'engine' => ['name' => 'sheetjs-ce', 'version' => '0.20.3'],
            'input' => ['bytes' => strlen($input), 'sha256' => hash('sha256', $input)],
            'package' => ['entryCount' => 9, 'expandedBytes' => 2_048],
            'manifest' => [
                'schema' => 'xlsx-view-manifest-v1',
                'profile' => 'xlsx-typed-view-v1',
                'workbook' => [
                    'visibleSheetCount' => 1,
                    'omittedHiddenSheetCount' => 1,
                    'cellCount' => 9,
                    'formulaCount' => 1,
                    'formulasWithoutCachedResultCount' => 1,
                    'linkCount' => 3,
                    'mergeCount' => 2,
                    'truncated' => false,
                ],
                'sheets' => [[
                    'name' => 'Summary',
                    'rowExtent' => 4,
                    'columnExtent' => 4,
                    'omittedHiddenRowCount' => 2,
                    'omittedHiddenColumnCount' => 1,
                    'merges' => [
                        ['start' => 'A3', 'end' => 'B3'],
                        ['start' => 'C3', 'end' => 'D3'],
                    ],
                    'cells' => [
                        [
                            'coordinate' => 'A1',
                            'kind' => 'string',
                            'display' => 'Open report',
                            'value' => 'Open report',
                            'link' => ['kind' => 'internal', 'sheet' => 'Summary', 'coordinate' => 'D4'],
                        ],
                        ['coordinate' => 'B1', 'kind' => 'boolean', 'display' => 'TRUE', 'value' => true],
                        ['coordinate' => 'C1', 'kind' => 'date', 'display' => '2026-08-31', 'value' => '2026-08-31'],
                        ['coordinate' => 'D1', 'kind' => 'error', 'display' => '#N/A', 'value' => '#N/A'],
                        ['coordinate' => 'A2', 'kind' => 'blank', 'display' => null, 'value' => null],
                        [
                            'coordinate' => 'B2',
                            'kind' => 'formula',
                            'display' => null,
                            'value' => null,
                            'formula' => 'NOW()',
                            'cachedResultAvailable' => false,
                        ],
                        [
                            'coordinate' => 'C2',
                            'kind' => 'string',
                            'display' => 'Mail support',
                            'value' => 'Mail support',
                            'link' => ['kind' => 'external', 'target' => 'mailto:support@example.com'],
                        ],
                        [
                            'coordinate' => 'D2',
                            'kind' => 'number',
                            'display' => '3.14',
                            'value' => 3.14,
                            'link' => ['kind' => 'external', 'target' => 'https://example.com/value'],
                        ],
                        ['coordinate' => 'D4', 'kind' => 'string', 'display' => 'Target', 'value' => 'Target'],
                    ],
                ]],
                'searchText' => "[Summary] A1 Open report\n"
                    . "[Summary] B1 TRUE\n"
                    . "[Summary] C1 2026-08-31\n"
                    . "[Summary] D1 #N/A\n"
                    . "[Summary] B2 =NOW()\n"
                    . "[Summary] C2 Mail support\n"
                    . "[Summary] D2 3.14\n"
                    . '[Summary] D4 Target',
            ],
        ];
    }

    private function assertRejected(
        string $response,
        string $input,
        ?string $expectedInputSha256 = null,
    ): void {
        try {
            XlsxProcessingResult::fromJson(
                $response,
                strlen($input),
                $expectedInputSha256 ?? hash('sha256', $input),
            );
            $this->fail('Malformed XLSX processor response must be rejected.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    private function encode(mixed $value): string
    {
        if (!is_array($value)) {
            throw new RuntimeException('Fixture response is invalid.');
        }

        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
