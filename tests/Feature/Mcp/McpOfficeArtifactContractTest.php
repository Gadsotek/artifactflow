<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\Output\McpXlsxSelectionView;
use App\Application\PageCatalog\DocxProcessorProtocol;
use App\Application\PageCatalog\PdfProcessingResult;
use App\Application\PageCatalog\PdfProcessorProtocol;
use App\Application\PageCatalog\XlsxProcessorProtocol;
use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

final class McpOfficeArtifactContractTest extends McpTestCase
{
    private const string XLSX_SECRET = 'test-mcp-xlsx-processor-secret-0000001';
    private const string DOCX_SECRET = 'test-mcp-docx-processor-secret-0000001';

    public function test_mcp_xlsx_create_replace_read_search_and_revert_never_return_original_bytes(): void
    {
        Storage::fake('artifacts');
        $this->enableXlsx();
        $this->fakeXlsxSequence(['firstxlsxneedle', 'secondxlsxneedle', 'restoredxlsxneedle']);
        $owner = $this->createUser('XLSX MCP Owner', 'xlsx-mcp-owner@example.test');
        $service = $this->createServiceAccount('XLSX MCP Agent', 'xlsx-mcp-agent@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'XLSX MCP Team');
        $this->addMember($workspace, $service, WorkspaceRole::Editor);
        $token = $this->issueToken($service, [
            McpAccessTokenIssuer::SCOPE_CREATE,
            McpAccessTokenIssuer::SCOPE_UPDATE,
            McpAccessTokenIssuer::SCOPE_UPLOAD,
            McpAccessTokenIssuer::SCOPE_READ,
            McpAccessTokenIssuer::SCOPE_SEARCH,
        ])->plainTextToken;
        $first = "PK\x03\x04private-xlsx-original-one";

        $created = $this->successfulToolPayload($this->callTool($token, 'create_xlsx', [
            'workspace_uid' => $workspace->uid,
            'title' => 'MCP Workbook',
            'xlsx_base64' => base64_encode($first),
            'status' => PageStatus::Approved->value,
            'change_summary' => 'Create the first workbook.',
        ]));
        $page = Page::query()->whereKey($this->payloadString($created, 'uid'))->sole();
        $firstVersion = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $this->assertSame(1, $this->payloadArray($created, 'xlsx')['visible_sheet_count']);

        $missingSelection = $this->toolErrorPayload($this->callTool($token, 'read', [
            'page_uid' => $page->uid,
        ]));
        $this->assertSame('invalid_request', $missingSelection['type']);

        $metadataOnly = $this->successfulToolPayload($this->callTool($token, 'read', [
            'page_uid' => $page->uid,
            'include' => [],
        ]));
        $this->assertArrayNotHasKey('xlsx_selection', $metadataOnly);

        $read = $this->successfulToolPayload($this->callTool($token, 'read', [
            'page_uid' => $page->uid,
            'xlsx_sheet' => 'Visible',
            'xlsx_range' => 'A1:A10',
        ]));
        $readJson = json_encode($read, JSON_THROW_ON_ERROR);
        $display = data_get($read, 'xlsx_selection.cells.0.display.data');
        $this->assertSame('firstxlsxneedle', $display);
        $this->assertSame('Visible', data_get($read, 'xlsx_selection.sheet.data'));
        $this->assertSame('A1:A10', data_get($read, 'xlsx_selection.range'));
        $this->assertFalse(data_get($read, 'xlsx_selection.has_cells_outside_range'));
        $this->assertStringNotContainsString('private-xlsx-original-one', $readJson);
        $this->assertStringNotContainsString('storage_path', $readJson);
        $this->assertStringNotContainsString('processor_profile', $readJson);

        $search = $this->payloadList($this->successfulToolPayload($this->callTool($token, 'search', [
            'query' => 'firstxlsxneedle',
            'type' => PageType::Xlsx->value,
        ])), 'results');
        $this->assertSame([$page->uid], array_column($search, 'uid'));
        $this->assertSame(1, data_get($search, '0.xlsx.visible_sheet_count'));
        $this->assertSame(0, data_get($search, '0.xlsx.omitted_hidden_sheet_count'));
        $this->assertArrayNotHasKey('processor_profile', $this->payloadArray($search[0], 'xlsx'));

        $searchOnlyToken = $this->issueToken($service, [
            McpAccessTokenIssuer::SCOPE_SEARCH,
        ])->plainTextToken;
        $searchWithoutRead = $this->payloadList($this->successfulToolPayload($this->callTool(
            $searchOnlyToken,
            'search',
            [
                'query' => 'firstxlsxneedle',
                'type' => PageType::Xlsx->value,
            ],
        )), 'results');
        $this->assertArrayNotHasKey('xlsx', $searchWithoutRead[0]);

        $second = "PK\x03\x04private-xlsx-original-two";
        $replaced = $this->successfulToolPayload($this->callTool($token, 'replace_xlsx', [
            'page_uid' => $page->uid,
            'base_version_uid' => $firstVersion->uid,
            'xlsx_base64' => base64_encode($second),
            'change_summary' => 'Replace workbook values.',
        ]));
        $secondVersionUid = $this->payloadString($replaced, 'current_version_uid');
        $this->assertSame($second, Storage::disk('artifacts')->get(
            PageVersion::query()->whereKey($secondVersionUid)->sole()->content_storage_path,
        ));

        $reverted = $this->successfulToolPayload($this->callTool($token, 'revert', [
            'page_uid' => $page->uid,
            'base_version_uid' => $secondVersionUid,
            'change_summary' => 'Restore the first workbook.',
        ]));
        $restored = PageVersion::query()->whereKey($this->payloadString($reverted, 'current_version_uid'))->sole();
        $this->assertSame($firstVersion->content_hash, $restored->content_hash);
        $this->assertSame('restoredxlsxneedle', data_get(
            $this->successfulToolPayload($this->callTool($token, 'read', [
                'page_uid' => $page->uid,
                'xlsx_sheet' => 'Visible',
                'xlsx_range' => 'A1:A10',
            ])),
            'xlsx_selection.cells.0.display.data',
        ));

        $missingSheet = $this->toolErrorPayload($this->callTool($token, 'read', [
            'page_uid' => $page->uid,
            'xlsx_sheet' => 'Hidden',
            'xlsx_range' => 'A1:A10',
        ]));
        $this->assertSame('invalid_request', $missingSheet['type']);

        $oversizedRange = $this->toolErrorPayload($this->callTool($token, 'read', [
            'page_uid' => $page->uid,
            'xlsx_sheet' => 'Visible',
            'xlsx_range' => 'A1:Z100',
        ]));
        $this->assertSame('invalid_request', $oversizedRange['type']);
    }

    public function test_mcp_xlsx_selection_rejects_a_wire_payload_over_two_megabytes(): void
    {
        $response = json_decode(
            $this->xlsxResponse('bounded-workbook', str_repeat('x', 1024 * 1024)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        if (!is_array($response)) {
            $this->fail('The XLSX processor fixture did not decode to an object.');
        }
        $manifestPayload = $response['manifest'] ?? null;
        if (!is_array($manifestPayload)) {
            $this->fail('The XLSX processor fixture did not contain a manifest object.');
        }
        $manifest = json_encode($manifestPayload, JSON_THROW_ON_ERROR);

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('Selected XLSX range exceeds the MCP response limit');

        McpXlsxSelectionView::fromJson($manifest, 'Visible', 'A1:A1');
    }

    public function test_mcp_docx_create_replace_read_search_and_revert_return_only_pdf_derived_text(): void
    {
        Storage::fake('artifacts');
        $this->enableDocx();
        $this->fakeDocxSequence(['firstdocxneedle', 'seconddocxneedle', 'restoreddocxneedle']);
        $owner = $this->createUser('DOCX MCP Owner', 'docx-mcp-owner@example.test');
        $service = $this->createServiceAccount('DOCX MCP Agent', 'docx-mcp-agent@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'DOCX MCP Team');
        $this->addMember($workspace, $service, WorkspaceRole::Editor);
        $token = $this->issueToken($service, [
            McpAccessTokenIssuer::SCOPE_CREATE,
            McpAccessTokenIssuer::SCOPE_UPDATE,
            McpAccessTokenIssuer::SCOPE_UPLOAD,
            McpAccessTokenIssuer::SCOPE_READ,
            McpAccessTokenIssuer::SCOPE_SEARCH,
        ])->plainTextToken;
        $first = "PK\x03\x04private-docx-original-one";

        $created = $this->successfulToolPayload($this->callTool($token, 'create_docx', [
            'workspace_uid' => $workspace->uid,
            'title' => 'MCP Word Document',
            'docx_base64' => base64_encode($first),
            'status' => PageStatus::Approved->value,
            'change_summary' => 'Create the first Word document.',
        ]));
        $page = Page::query()->whereKey($this->payloadString($created, 'uid'))->sole();
        $firstVersion = PageVersion::query()->whereKey($page->current_version_uid)->sole();
        $this->assertSame('indexed', $this->payloadArray($created, 'docx')['extraction_state']);

        $read = $this->successfulToolPayload($this->callTool($token, 'read', ['page_uid' => $page->uid]));
        $readJson = json_encode($read, JSON_THROW_ON_ERROR);
        $this->assertSame('firstdocxneedle', data_get($read, 'content.data'));
        $this->assertStringNotContainsString('private-docx-original-one', $readJson);
        $this->assertStringNotContainsString('%PDF-', $readJson);
        $this->assertStringNotContainsString('processor_profile', $readJson);
        $this->assertStringNotContainsString('storage_path', $readJson);

        $search = $this->payloadList($this->successfulToolPayload($this->callTool($token, 'search', [
            'query' => 'firstdocxneedle',
            'type' => PageType::Docx->value,
        ])), 'results');
        $this->assertSame([$page->uid], array_column($search, 'uid'));
        $this->assertSame(1, data_get($search, '0.docx.page_count'));
        $this->assertSame('indexed', data_get($search, '0.docx.extraction_state'));
        $this->assertArrayNotHasKey('processor_profile', $this->payloadArray($search[0], 'docx'));

        $second = "PK\x03\x04private-docx-original-two";
        $replaced = $this->successfulToolPayload($this->callTool($token, 'replace_docx', [
            'page_uid' => $page->uid,
            'base_version_uid' => $firstVersion->uid,
            'docx_base64' => base64_encode($second),
            'change_summary' => 'Replace the Word document.',
        ]));
        $secondVersionUid = $this->payloadString($replaced, 'current_version_uid');
        $this->assertSame($second, Storage::disk('artifacts')->get(
            PageVersion::query()->whereKey($secondVersionUid)->sole()->content_storage_path,
        ));

        $reverted = $this->successfulToolPayload($this->callTool($token, 'revert', [
            'page_uid' => $page->uid,
            'base_version_uid' => $secondVersionUid,
            'change_summary' => 'Restore the first Word document.',
        ]));
        $restored = PageVersion::query()->whereKey($this->payloadString($reverted, 'current_version_uid'))->sole();
        $this->assertSame($firstVersion->content_hash, $restored->content_hash);
        $this->assertSame('restoreddocxneedle', data_get(
            $this->successfulToolPayload($this->callTool($token, 'read', ['page_uid' => $page->uid])),
            'content.data',
        ));
    }

    public function test_office_scope_authority_concurrency_and_transport_checks_precede_processor_work(): void
    {
        Storage::fake('artifacts');
        $this->enableXlsx();
        $this->enableDocx();
        Http::swap(new HttpFactory(app('events')));
        Http::fake();

        $owner = $this->createUser('Office Boundary Owner', 'office-boundary-owner@example.test');
        $otherOwner = $this->createUser('Other Office Owner', 'other-office-owner@example.test');
        $service = $this->createServiceAccount('Office Boundary Agent', 'office-boundary-agent@example.test');
        $allowedWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Allowed Office Workspace');
        $otherWorkspace = app(CreateSharedWorkspace::class)->handle($otherOwner, 'Other Office Workspace');
        $this->addMember($allowedWorkspace, $service, WorkspaceRole::Editor);
        $this->addMember($otherWorkspace, $service, WorkspaceRole::Editor);

        foreach ([
            [
                'type' => PageType::Xlsx,
                'create_tool' => 'create_xlsx',
                'replace_tool' => 'replace_xlsx',
                'binary_argument' => 'xlsx_base64',
            ],
            [
                'type' => PageType::Docx,
                'create_tool' => 'create_docx',
                'replace_tool' => 'replace_docx',
                'binary_argument' => 'docx_base64',
            ],
        ] as $case) {
            $type = $case['type'];
            $createTool = $case['create_tool'];
            $replaceTool = $case['replace_tool'];
            $binaryArgument = $case['binary_argument'];
            $label = strtoupper($type->value);

            $withoutUpload = $this->issueToken($service, [
                McpAccessTokenIssuer::SCOPE_CREATE,
            ])->plainTextToken;
            $missingScope = $this->toolErrorPayload($this->callTool($withoutUpload, $createTool, [
                'workspace_uid' => $allowedWorkspace->uid,
                'title' => $label . ' Must Not Decode',
                $binaryArgument => 'not base64',
                'change_summary' => 'Attempt without upload scope.',
            ]));
            $this->assertSame('insufficient_scope', $missingScope['type']);

            $workspaceScoped = $this->issueToken($service, [
                McpAccessTokenIssuer::SCOPE_CREATE,
                McpAccessTokenIssuer::SCOPE_UPLOAD,
            ], workspaceUids: [$allowedWorkspace->uid])->plainTextToken;
            $wrongWorkspace = $this->toolErrorPayload($this->callTool($workspaceScoped, $createTool, [
                'workspace_uid' => $otherWorkspace->uid,
                'title' => $label . ' Outside Token Ceiling',
                $binaryArgument => 'not base64',
                'change_summary' => 'Attempt outside the token workspace ceiling.',
            ]));
            $this->assertSame('not_found', $wrongWorkspace['type']);
            $this->assertSame('Workspace not found.', $wrongWorkspace['message']);

            $invalidTransport = $this->toolErrorPayload($this->callTool($workspaceScoped, $createTool, [
                'workspace_uid' => $allowedWorkspace->uid,
                'title' => $label . ' Invalid Transport',
                $binaryArgument => 'not base64',
                'change_summary' => 'Reject a noncanonical upload.',
            ]));
            $this->assertSame('invalid_request', $invalidTransport['type']);

            $editablePage = Page::factory()->create([
                'owner_user_uid' => $owner->uid,
                'workspace_uid' => $allowedWorkspace->uid,
                'type' => $type,
            ]);
            $currentVersion = PageVersion::factory()->forPage($editablePage)->create();
            $editablePage->forceFill(['current_version_uid' => $currentVersion->uid])->save();
            $replaceScoped = $this->issueToken($service, [
                McpAccessTokenIssuer::SCOPE_UPDATE,
                McpAccessTokenIssuer::SCOPE_UPLOAD,
            ], workspaceUids: [$allowedWorkspace->uid])->plainTextToken;
            $stale = $this->toolErrorPayload($this->callTool($replaceScoped, $replaceTool, [
                'page_uid' => $editablePage->uid,
                'base_version_uid' => '01J00000000000000000000000',
                $binaryArgument => 'not base64',
                'change_summary' => 'Reject stale concurrency before decoding.',
            ]));
            $this->assertSame('conflict', $stale['type']);
            $this->assertSame($currentVersion->uid, $stale['current_version_uid']);
        }

        Http::assertNothingSent();
        $this->assertDatabaseMissing('pages', ['title' => 'XLSX Must Not Decode']);
        $this->assertDatabaseMissing('pages', ['title' => 'DOCX Must Not Decode']);
        $this->assertDatabaseMissing('pages', ['title' => 'XLSX Outside Token Ceiling']);
        $this->assertDatabaseMissing('pages', ['title' => 'DOCX Outside Token Ceiling']);
    }

    private function enableXlsx(): void
    {
        config([
            'xlsx_processor.enabled' => true,
            'xlsx_processor.url' => 'http://xlsx-processor.test',
            'xlsx_processor.socket_path' => null,
            'xlsx_processor.shared_secret' => self::XLSX_SECRET,
            'xlsx_processor.connect_timeout_seconds' => 2,
            'xlsx_processor.timeout_seconds' => 15,
        ]);
        Cache::lock(\App\Application\PageCatalog\XlsxProcessingAdmission::SLOT_KEY)->forceRelease();
    }

    private function enableDocx(): void
    {
        $this->enablePdfProcessor();
        config([
            'docx_processor.enabled' => true,
            'docx_processor.url' => 'http://docx-processor.test',
            'docx_processor.socket_path' => null,
            'docx_processor.shared_secret' => self::DOCX_SECRET,
            'docx_processor.connect_timeout_seconds' => 2,
            'docx_processor.timeout_seconds' => 35,
        ]);
        Cache::lock(\App\Application\PageCatalog\DocxProcessingAdmission::SLOT_KEY)->forceRelease();
    }

    /** @param list<string> $values */
    private function fakeXlsxSequence(array $values): void
    {
        Http::swap(new HttpFactory(app('events')));
        Http::fake(function (Request $request) use (&$values): \GuzzleHttp\Promise\PromiseInterface {
            $value = array_shift($values);
            $this->assertIsString($value);
            $xlsx = $request->body();
            $nonce = $request->header('X-ArtifactFlow-Processor-Nonce')[0] ?? '';
            $this->assertIsString($nonce);
            $body = $this->xlsxResponse($xlsx, $value);
            $inputHash = hash('sha256', $xlsx);

            return Http::response($body, 200, [
                'Cache-Control' => 'no-store',
                'Content-Type' => XlsxProcessorProtocol::MANIFEST_MEDIA_TYPE,
                'Content-Length' => (string) strlen($body),
                'X-Content-Type-Options' => 'nosniff',
                'X-ArtifactFlow-Processor-Nonce' => $nonce,
                'X-ArtifactFlow-Input-Bytes' => (string) strlen($xlsx),
                'X-ArtifactFlow-Input-SHA256' => $inputHash,
                'X-ArtifactFlow-Response-SHA256' => hash('sha256', $body),
                'X-ArtifactFlow-Processor-Profile' => XlsxProcessorProtocol::PROCESSOR_PROFILE,
                'X-ArtifactFlow-Processor-Schema' => XlsxProcessorProtocol::RESPONSE_SCHEMA,
                'X-ArtifactFlow-Processor-Engine' => XlsxProcessorProtocol::ENGINE_NAME,
                'X-ArtifactFlow-Processor-Engine-Version' => XlsxProcessorProtocol::ENGINE_VERSION,
                'X-ArtifactFlow-Processor-Signature' => XlsxProcessorProtocol::responseSignature(
                    $nonce,
                    strlen($xlsx),
                    $inputHash,
                    $body,
                    self::XLSX_SECRET,
                ),
            ]);
        });
    }

    private function xlsxResponse(string $xlsx, string $value): string
    {
        return json_encode([
            'schema' => XlsxProcessorProtocol::RESPONSE_SCHEMA,
            'profile' => XlsxProcessorProtocol::PROCESSOR_PROFILE,
            'engine' => ['name' => XlsxProcessorProtocol::ENGINE_NAME, 'version' => XlsxProcessorProtocol::ENGINE_VERSION],
            'input' => ['bytes' => strlen($xlsx), 'sha256' => hash('sha256', $xlsx)],
            'package' => ['entryCount' => 8, 'expandedBytes' => 1_024],
            'manifest' => [
                'schema' => 'xlsx-view-manifest-v1',
                'profile' => XlsxProcessorProtocol::PROCESSOR_PROFILE,
                'workbook' => [
                    'visibleSheetCount' => 1,
                    'omittedHiddenSheetCount' => 0,
                    'cellCount' => 1,
                    'formulaCount' => 0,
                    'formulasWithoutCachedResultCount' => 0,
                    'linkCount' => 0,
                    'mergeCount' => 0,
                    'truncated' => false,
                ],
                'sheets' => [[
                    'name' => 'Visible',
                    'rowExtent' => 1,
                    'columnExtent' => 1,
                    'omittedHiddenRowCount' => 0,
                    'omittedHiddenColumnCount' => 0,
                    'merges' => [],
                    'cells' => [[
                        'coordinate' => 'A1',
                        'kind' => 'string',
                        'display' => $value,
                        'value' => $value,
                    ]],
                ]],
                'searchText' => '[Visible] A1 ' . $value,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param list<string> $texts */
    private function fakeDocxSequence(array $texts): void
    {
        Http::swap(new HttpFactory(app('events')));
        $previews = array_map(
            static fn (string $text, int $index): string => "%PDF-1.7\npreview-{$index}-{$text}\n%%EOF\n",
            $texts,
            array_keys($texts),
        );
        Http::fake(function (Request $request) use (&$texts, &$previews): \GuzzleHttp\Promise\PromiseInterface {
            if (str_ends_with($request->url(), '/v1/docx/previews')) {
                $pdf = array_shift($previews);
                $this->assertIsString($pdf);
                $docx = $request->body();
                $nonce = $request->header('X-ArtifactFlow-Processor-Nonce')[0] ?? '';
                $this->assertIsString($nonce);
                $inputHash = hash('sha256', $docx);

                return Http::response($pdf, 200, [
                    'Cache-Control' => 'no-store',
                    'Content-Type' => DocxProcessorProtocol::OUTPUT_MEDIA_TYPE,
                    'X-Content-Type-Options' => 'nosniff',
                    'X-ArtifactFlow-Processor-Nonce' => $nonce,
                    'X-ArtifactFlow-Input-Bytes' => (string) strlen($docx),
                    'X-ArtifactFlow-Input-SHA256' => $inputHash,
                    'X-ArtifactFlow-Response-SHA256' => hash('sha256', $pdf),
                    'X-ArtifactFlow-Processor-Profile' => DocxProcessorProtocol::PROCESSOR_PROFILE,
                    'X-ArtifactFlow-Processor-Schema' => DocxProcessorProtocol::RESPONSE_SCHEMA,
                    'X-ArtifactFlow-Processor-Engine' => DocxProcessorProtocol::ENGINE_NAME,
                    'X-ArtifactFlow-Processor-Engine-Version' => DocxProcessorProtocol::ENGINE_VERSION,
                    'X-ArtifactFlow-Package-Entry-Count' => '7',
                    'X-ArtifactFlow-Package-Expanded-Bytes' => '2048',
                    'X-ArtifactFlow-Package-Relationship-Count' => '2',
                    'X-ArtifactFlow-Package-Media-Count' => '0',
                    'X-ArtifactFlow-Package-External-Hyperlink-Count' => '1',
                    'X-ArtifactFlow-Processor-Signature' => DocxProcessorProtocol::responseSignature(
                        $nonce,
                        strlen($docx),
                        $inputHash,
                        $pdf,
                        7,
                        2_048,
                        2,
                        0,
                        1,
                        self::DOCX_SECRET,
                    ),
                ]);
            }

            $text = array_shift($texts);
            $this->assertIsString($text);
            $pdf = $request->body();
            $nonce = $request->header('X-ArtifactFlow-Processor-Nonce')[0] ?? '';
            $this->assertIsString($nonce);
            $body = json_encode([
                'page_count' => 1,
                'pdf_version' => '1.7',
                'extraction_state' => 'indexed',
                'processor_profile' => PdfProcessingResult::DOCX_PREVIEW_PROCESSOR_PROFILE,
                'text' => $text,
            ], JSON_THROW_ON_ERROR);

            return Http::response($body, 200, [
                'Content-Type' => 'application/json',
                'X-ArtifactFlow-Processor-Signature' => PdfProcessorProtocol::responseSignature(
                    $nonce,
                    hash('sha256', $pdf),
                    $body,
                    self::PDF_PROCESSOR_SECRET,
                    PdfProcessorProtocol::DOCX_PREVIEW_PROFILE,
                ),
            ]);
        });
    }
}
