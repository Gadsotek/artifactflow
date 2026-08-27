<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageType;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\Page;
use App\Models\ProducerAssertion;
use Illuminate\Support\Facades\Storage;

final class McpProvenanceContractTest extends McpTestCase
{
    public function test_mcp_revert_compacts_page_and_effective_origin_producers_without_relabeling_restore(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Restore Provenance Owner', 'restore-provenance-owner@example.test');
        $service = $this->createServiceAccount('Restore Provenance Agent', 'restore-provenance-agent@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Restore Provenance Team');
        $this->addMember($workspace, $service, WorkspaceRole::Editor);
        $token = $this->issueToken($service, [
            McpAccessTokenIssuer::SCOPE_CREATE,
            McpAccessTokenIssuer::SCOPE_READ,
            McpAccessTokenIssuer::SCOPE_UPDATE,
        ])->plainTextToken;

        $created = $this->successfulToolPayload($this->callTool($token, 'create', [
            'workspace_uid' => $workspace->uid,
            'type' => PageType::Markdown->value,
            'title' => 'Restore lineage',
            'content' => '# Produced version',
            'change_summary' => 'Create the produced version.',
            'provenance' => [
                'producers' => [[
                    'kind' => 'ai',
                    'provider' => 'openai',
                    'model_label' => 'GPT-5 family',
                ]],
            ],
        ]));
        $pageUid = $this->payloadString($created, 'uid');
        $firstVersionUid = $this->payloadString($created, 'current_version_uid');
        $updated = $this->successfulToolPayload($this->callTool($token, 'update', [
            'page_uid' => $pageUid,
            'content' => '# Temporary version',
            'base_version_uid' => $firstVersionUid,
            'change_summary' => 'Add a temporary version.',
        ]));
        $secondVersionUid = $this->payloadString($updated, 'version_uid');
        $reverted = $this->successfulToolPayload($this->callTool($token, 'revert', [
            'page_uid' => $pageUid,
            'base_version_uid' => $secondVersionUid,
            'change_summary' => 'Restore the produced version.',
        ]));
        $restoredVersionUid = $this->payloadString($reverted, 'version_uid');
        $read = $this->successfulToolPayload($this->callTool($token, 'read', [
            'page_uid' => $pageUid,
        ]));
        $provenance = $this->payloadArray($read, 'provenance');
        $ingest = $this->payloadArray($provenance, 'version_ingest');
        $producers = $this->payloadList($provenance, 'producers');
        $effectiveOrigin = $this->payloadArray($provenance, 'effective_content_origin');

        $this->assertCount(1, $producers);
        $producerUid = $this->payloadString($producers[0], 'uid');
        $this->assertSame([$producerUid], $provenance['page_origin_producer_uids']);
        $this->assertSame([], $provenance['direct_version_producer_uids']);
        $this->assertSame([$producerUid], $effectiveOrigin['producer_uids']);
        $this->assertSame($firstVersionUid, $effectiveOrigin['page_version_uid']);
        $this->assertSame($firstVersionUid, $ingest['derived_from_version_uid']);
        $this->assertSame($firstVersionUid, $ingest['content_equivalent_to_version_uid']);
        $this->assertSame($firstVersionUid, $ingest['content_origin_version_uid']);
        $this->assertSame($restoredVersionUid, $ingest['page_version_uid']);
        $this->assertArrayNotHasKey('page_origin_producers', $provenance);
        $this->assertArrayNotHasKey('direct_version_producers', $provenance);
    }

    public function test_mcp_create_records_declared_producers_reported_client_and_safe_external_references(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Provenance Owner', 'provenance-owner@example.test');
        $service = $this->createServiceAccount('Provenance Agent', 'provenance-agent@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Provenance Team');
        $this->addMember($workspace, $service, WorkspaceRole::Editor);
        $token = $this->issueToken($service, [
            McpAccessTokenIssuer::SCOPE_CREATE,
            McpAccessTokenIssuer::SCOPE_READ,
            McpAccessTokenIssuer::SCOPE_SEARCH,
        ])->plainTextToken;

        $initialize = $this->postMcp($token, [
            'jsonrpc' => '2.0',
            'id' => 'provenance-init',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => [],
                'clientInfo' => [
                    'name' => 'claude-code',
                    'version' => '3.1.0',
                ],
            ],
        ])->assertOk();
        $sessionId = $initialize->headers->get('MCP-Session-Id');
        $this->assertIsString($sessionId);
        $this->assertNotSame('', $sessionId);

        $created = $this->successfulToolPayload($this->callTool($token, 'create', [
            'workspace_uid' => $workspace->uid,
            'type' => PageType::Markdown->value,
            'title' => 'Invoice Dashboard',
            'content' => '# Invoice dashboard',
            'change_summary' => 'Create the invoice dashboard.',
            'provenance' => [
                'producers' => [[
                    'kind' => 'ai',
                    'provider' => 'Anthropic',
                    'model_id' => 'claude-opus-5-2-20260715',
                    'model_label' => 'Claude Opus 5.2',
                    'model_version' => '20260715',
                    'generated_at' => '2026-08-01T13:42:00.123Z',
                    'references' => [[
                        'kind' => 'conversation',
                        'ref' => 'abc123',
                        'url' => 'https://claude.ai/chat/abc123',
                    ]],
                ]],
            ],
        ], $sessionId));
        $pageUid = $this->payloadString($created, 'uid');
        $versionUid = $this->payloadString($created, 'current_version_uid');
        $storedProvenance = $this->payloadArray($created, 'stored_provenance');

        $this->assertTrue($storedProvenance['supplied']);
        $this->assertSame('complete', $storedProvenance['completeness']);
        $this->assertCount(1, $this->payloadList($storedProvenance, 'direct_version_producers'));

        $this->assertDatabaseHas('page_version_ingests', [
            'page_uid' => $pageUid,
            'page_version_uid' => $versionUid,
            'version_number' => 1,
            'operation' => 'create',
            'ingest_method' => 'mcp',
            'actor_user_uid' => $service->uid,
            'mcp_client_reported_name' => 'claude-code',
            'mcp_client_reported_version' => '3.1.0',
            'provenance_supplied_at_ingest' => true,
        ]);
        $this->assertDatabaseHas('producer_assertions', [
            'producer_kind' => 'ai',
            'provider_key' => 'anthropic',
            'model_id' => 'claude-opus-5-2-20260715',
            'model_label' => 'Claude Opus 5.2',
            'model_version' => '20260715',
            'evidence_type' => 'self_reported',
            'asserted_by_user_uid' => $service->uid,
        ]);
        $storedProducer = ProducerAssertion::query()
            ->where('model_id', 'claude-opus-5-2-20260715')
            ->sole();
        $this->assertSame(
            '2026-08-01T13:42:00.123000+00:00',
            $storedProducer->generated_at?->utc()->format('Y-m-d\TH:i:s.uP'),
        );
        $this->assertDatabaseHas('external_origin_references', [
            'reference_kind' => 'conversation',
            'external_ref' => 'abc123',
            'url' => 'https://claude.ai/chat/abc123',
            'retention_class' => 'sensitive',
        ]);

        $read = $this->successfulToolPayload($this->callTool($token, 'read', [
            'page_uid' => $pageUid,
        ], $sessionId));
        $provenance = $this->payloadArray($read, 'provenance');
        $ingest = $this->payloadArray($provenance, 'version_ingest');
        $producers = $this->payloadList($provenance, 'producers');
        $producer = $producers[0];
        $provider = $this->payloadArray($producer, 'provider');
        $model = $this->payloadArray($producer, 'model_id');
        $references = $this->payloadList($producer, 'references');
        $reference = $references[0];

        $this->assertSame('complete', $provenance['provenance_completeness']);
        $this->assertSame('self_reported', $provenance['strongest_evidence']);
        $this->assertCount(1, $producers);
        $this->assertSame([$producer['uid']], $provenance['page_origin_producer_uids']);
        $this->assertSame([$producer['uid']], $provenance['direct_version_producer_uids']);
        $effectiveOrigin = $this->payloadArray($provenance, 'effective_content_origin');
        $this->assertSame([$producer['uid']], $effectiveOrigin['producer_uids']);
        $this->assertArrayNotHasKey('page_origin_producers', $provenance);
        $this->assertArrayNotHasKey('direct_version_producers', $provenance);
        $this->assertSame('mcp', $ingest['ingest_method']);
        $this->assertSame('claude-code', $this->payloadString(
            $this->payloadArray($ingest, 'mcp_reported_client_name'),
            'data',
        ));
        $this->assertSame('anthropic', $this->payloadString($provider, 'data'));
        $this->assertSame('claude-opus-5-2-20260715', $this->payloadString($model, 'data'));
        $this->assertSame('abc123', $this->payloadString(
            $this->payloadArray($reference, 'ref'),
            'data',
        ));
        $this->assertSame('https://claude.ai/chat/abc123', $this->payloadString(
            $this->payloadArray($reference, 'url'),
            'data',
        ));

        $search = $this->successfulToolPayload($this->callTool($token, 'search', [
            'ai_provider' => 'anthropic',
            'ai_model_query' => 'opus',
            'provenance_scope' => 'any_version',
        ], $sessionId));
        $this->assertSame([$pageUid], array_column($this->payloadList($search, 'results'), 'uid'));

        $event = DomainEvent::query()->where('event_type', 'page.version.producer_asserted')->sole();
        $audit = AuditEntry::query()->where('action', 'page.version.producer_asserted')->sole();
        $recordedTrace = json_encode([$event->payload, $audit->metadata], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('abc123', $recordedTrace);
        $this->assertStringNotContainsString('claude.ai', $recordedTrace);
        $this->assertArrayNotHasKey('provider_key', $event->payload);
        $this->assertArrayNotHasKey('model_id', $event->payload);
        $this->assertArrayNotHasKey('provider_key', $audit->metadata);
        $this->assertArrayNotHasKey('model_id', $audit->metadata);

        $this->actingAs($owner)
            ->get("/pages/{$pageUid}")
            ->assertOk()
            ->assertSee('MCP-reported client')
            ->assertDontSee('Observed MCP client');
    }

    public function test_mcp_preserves_partial_ai_provenance_and_discloses_exactly_what_was_stored(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Partial Provenance Owner', 'partial-provenance-owner@example.test');
        $service = $this->createServiceAccount('Partial Provenance Agent', 'partial-provenance-agent@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Partial Provenance Team');
        $this->addMember($workspace, $service, WorkspaceRole::Editor);
        $token = $this->issueToken($service, [
            McpAccessTokenIssuer::SCOPE_CREATE,
            McpAccessTokenIssuer::SCOPE_READ,
            McpAccessTokenIssuer::SCOPE_SEARCH,
        ])->plainTextToken;

        $created = $this->successfulToolPayload($this->callTool($token, 'create', [
            'workspace_uid' => $workspace->uid,
            'type' => PageType::Markdown->value,
            'title' => 'Partial GPT Artifact',
            'content' => '# Partial producer identity',
            'change_summary' => 'Create content with the known partial producer identity.',
            'provenance' => [
                'producers' => [[
                    'kind' => 'ai',
                    'provider' => 'OpenAI',
                    'model_label' => 'GPT-5 family',
                    'extensions' => [[
                        'key' => 'openai.runtime_product',
                        'value' => 'Codex',
                    ]],
                ]],
            ],
        ]));
        $stored = $this->payloadArray($created, 'stored_provenance');
        $storedProducer = $this->payloadList($stored, 'direct_version_producers')[0];

        $this->assertTrue($stored['supplied']);
        $this->assertSame('partial', $stored['completeness']);
        $this->assertSame('model_label', $storedProducer['identity_precision']);
        $this->assertSame('OpenAI', $this->payloadString(
            $this->payloadArray($storedProducer, 'reported_provider'),
            'data',
        ));
        $this->assertArrayNotHasKey('model_id', $storedProducer);
        $this->assertArrayNotHasKey('name', $storedProducer);
        $this->assertArrayNotHasKey('version', $storedProducer);
        $this->assertArrayNotHasKey('model_version', $storedProducer);
        $this->assertSame('GPT-5 family', $this->payloadString(
            $this->payloadArray($storedProducer, 'model_label'),
            'data',
        ));
        $extension = $this->payloadList($storedProducer, 'extensions')[0];
        $this->assertSame('openai.runtime_product', $this->payloadString(
            $this->payloadArray($extension, 'key'),
            'data',
        ));
        $this->assertSame('Codex', $this->payloadString(
            $this->payloadArray($extension, 'value'),
            'data',
        ));

        $assertion = ProducerAssertion::query()->sole();
        $this->assertSame('OpenAI', $assertion->reported_provider);
        $this->assertSame('openai', $assertion->provider_key);
        $this->assertNull($assertion->model_id);
        $this->assertSame('GPT-5 family', $assertion->model_label);
        $this->assertSame([[
            'key' => 'openai.runtime_product',
            'value' => 'Codex',
        ]], $assertion->claim_extensions);

        $search = $this->successfulToolPayload($this->callTool($token, 'search', [
            'ai_provider' => 'OpenAI',
            'ai_model_query' => 'GPT-5',
            'provenance_scope' => 'current_version',
        ]));
        $this->assertSame(
            [$this->payloadString($created, 'uid')],
            array_column($this->payloadList($search, 'results'), 'uid'),
        );

        $this->actingAs($owner)
            ->get('/pages/' . $this->payloadString($created, 'uid'))
            ->assertOk()
            ->assertSee('OpenAI')
            ->assertSee('GPT-5 family')
            ->assertSee('Partial identity');
    }

    public function test_mcp_preserves_reference_only_ai_provenance(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Reference Provenance Owner', 'reference-provenance-owner@example.test');
        $service = $this->createServiceAccount(
            'Reference Provenance Agent',
            'reference-provenance-agent@example.test',
        );
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Reference Provenance Team');
        $this->addMember($workspace, $service, WorkspaceRole::Editor);
        $token = $this->issueToken($service, [
            McpAccessTokenIssuer::SCOPE_CREATE,
        ])->plainTextToken;

        $created = $this->successfulToolPayload($this->callTool($token, 'create', [
            'workspace_uid' => $workspace->uid,
            'type' => PageType::Markdown->value,
            'title' => 'Reference-only Artifact',
            'content' => '# Reference-only producer claim',
            'change_summary' => 'Create content with the only known producer reference.',
            'provenance' => [
                'producers' => [[
                    'kind' => 'ai',
                    'references' => [[
                        'kind' => 'conversation',
                        'ref' => 'conversation-123',
                    ]],
                ]],
            ],
        ]));
        $stored = $this->payloadArray($created, 'stored_provenance');
        $producer = $this->payloadList($stored, 'direct_version_producers')[0];

        $this->assertTrue($stored['supplied']);
        $this->assertSame('partial', $stored['completeness']);
        $this->assertSame('unspecified', $producer['identity_precision']);
        $references = $this->payloadList($producer, 'references');
        $this->assertCount(1, $references);
        $this->assertArrayNotHasKey('url', $references[0]);
        $this->assertDatabaseHas('external_origin_references', [
            'reference_kind' => 'conversation',
            'external_ref' => 'conversation-123',
        ]);
    }

    public function test_mcp_update_records_exact_model_and_search_respects_provenance_scope(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Scoped Provenance Owner', 'scoped-provenance-owner@example.test');
        $service = $this->createServiceAccount('Scoped Provenance Agent', 'scoped-provenance-agent@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Scoped Provenance Team');
        $this->addMember($workspace, $service, WorkspaceRole::Editor);
        $token = $this->issueToken($service, [
            McpAccessTokenIssuer::SCOPE_CREATE,
            McpAccessTokenIssuer::SCOPE_UPDATE,
            McpAccessTokenIssuer::SCOPE_READ,
            McpAccessTokenIssuer::SCOPE_SEARCH,
        ])->plainTextToken;

        $created = $this->successfulToolPayload($this->callTool($token, 'create', [
            'workspace_uid' => $workspace->uid,
            'type' => PageType::Markdown->value,
            'title' => 'Model Scope Dashboard',
            'content' => '# Initial human draft',
            'change_summary' => 'Create the initial model-scope draft.',
        ]));
        $pageUid = $this->payloadString($created, 'uid');
        $firstVersionUid = $this->payloadString($created, 'current_version_uid');

        $updated = $this->successfulToolPayload($this->callTool($token, 'update', [
            'page_uid' => $pageUid,
            'content' => '# Opus revision',
            'base_version_uid' => $firstVersionUid,
            'change_summary' => 'Apply the Opus revision.',
            'provenance' => [
                'producers' => [[
                    'kind' => 'ai',
                    'provider' => 'anthropic',
                    'model_id' => 'claude-opus-5-2-20260715',
                    'model_label' => 'Claude Opus 5.2',
                ]],
            ],
        ]));
        $secondVersionUid = $this->payloadString($updated, 'version_uid');
        $updatedProvenance = $this->payloadArray($updated, 'stored_provenance');

        $this->assertTrue($updatedProvenance['supplied']);
        $this->assertSame('complete', $updatedProvenance['completeness']);

        $read = $this->successfulToolPayload($this->callTool($token, 'read', [
            'page_uid' => $pageUid,
        ]));
        $provenance = $this->payloadArray($read, 'provenance');
        $ingest = $this->payloadArray($provenance, 'version_ingest');
        $producers = $this->payloadList($provenance, 'producers');
        $producer = $producers[0];

        $this->assertSame($secondVersionUid, $ingest['page_version_uid']);
        $this->assertSame('update', $ingest['operation']);
        $this->assertSame([$producer['uid']], $provenance['direct_version_producer_uids']);
        $this->assertArrayNotHasKey('mcp_reported_client_name', $ingest);
        $this->assertArrayNotHasKey('mcp_reported_client_version', $ingest);
        $this->assertSame('claude-opus-5-2-20260715', $this->payloadString(
            $this->payloadArray($producer, 'model_id'),
            'data',
        ));

        foreach ([
            'page_origin' => [],
            'current_version' => [$pageUid],
            'any_version' => [$pageUid],
        ] as $scope => $expectedPageUids) {
            $search = $this->successfulToolPayload($this->callTool($token, 'search', [
                'ai_provider' => 'Anthropic',
                'ai_model_query' => 'opus 5.2',
                'provenance_scope' => $scope,
            ]));
            $this->assertSame(
                $expectedPageUids,
                array_column($this->payloadList($search, 'results'), 'uid'),
            );
        }

        $third = $this->successfulToolPayload($this->callTool($token, 'update', [
            'page_uid' => $pageUid,
            'content' => '# Later unclaimed revision',
            'base_version_uid' => $secondVersionUid,
            'change_summary' => 'Apply an unclaimed revision.',
        ]));
        $this->assertNotSame($secondVersionUid, $this->payloadString($third, 'version_uid'));
        $thirdProvenance = $this->payloadArray($third, 'stored_provenance');
        $this->assertFalse($thirdProvenance['supplied']);
        $this->assertSame('none', $thirdProvenance['completeness']);

        $currentSearch = $this->successfulToolPayload($this->callTool($token, 'search', [
            'ai_provider' => 'anthropic',
            'ai_model_query' => 'claude-opus-5-2-20260715',
            'provenance_scope' => 'current_version',
        ]));
        $anyVersionSearch = $this->successfulToolPayload($this->callTool($token, 'search', [
            'ai_provider' => 'anthropic',
            'ai_model_query' => 'claude-opus-5-2-20260715',
            'provenance_scope' => 'any_version',
        ]));

        $this->assertSame([], $this->payloadList($currentSearch, 'results'));
        $this->assertSame([$pageUid], array_column($this->payloadList($anyVersionSearch, 'results'), 'uid'));
    }

    public function test_mcp_provenance_is_optional_and_invalid_claims_fail_before_page_creation(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Optional Provenance Owner', 'optional-provenance-owner@example.test');
        $service = $this->createServiceAccount('Optional Provenance Agent', 'optional-provenance-agent@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Optional Provenance Team');
        $this->addMember($workspace, $service, WorkspaceRole::Editor);
        $token = $this->issueToken($service, [
            McpAccessTokenIssuer::SCOPE_CREATE,
            McpAccessTokenIssuer::SCOPE_READ,
        ])->plainTextToken;

        $created = $this->successfulToolPayload($this->callTool($token, 'create', [
            'workspace_uid' => $workspace->uid,
            'type' => PageType::Markdown->value,
            'title' => 'Unknown Producer',
            'content' => '# Unknown producer',
            'change_summary' => 'Create a version without provenance.',
        ]));
        $pageUid = $this->payloadString($created, 'uid');
        $read = $this->successfulToolPayload($this->callTool($token, 'read', [
            'page_uid' => $pageUid,
        ]));
        $provenance = $this->payloadArray($read, 'provenance');

        $this->assertSame('none', $provenance['provenance_completeness']);
        $this->assertSame('none', $provenance['strongest_evidence']);
        $this->assertSame([], $provenance['producers']);
        $this->assertSame([], $provenance['direct_version_producer_uids']);
        $this->assertDatabaseHas('page_version_ingests', [
            'page_uid' => $pageUid,
            'provenance_supplied_at_ingest' => false,
        ]);
        $this->assertDatabaseCount('producer_assertions', 0);

        foreach ([
            [
                'kind' => 'ai',
            ],
            [
                'kind' => 'ai',
                'provider' => 'anthropic',
                'model_id' => 'claude-opus',
                'references' => [[
                    'kind' => 'conversation',
                    'url' => 'https://user:secret@claude.ai/chat/credential-leak',
                ]],
            ],
            [
                'kind' => 'mixed',
            ],
            [
                'kind' => 'ai',
                'provider' => 'anthropic',
                'model_id' => 'claude-opus',
                'generated_at' => 'not-a-timestamp',
            ],
            [
                'kind' => 'ai',
                'provider' => 'anthropic',
                'model_id' => 'claude-opus',
                'generated_at' => '2026-02-30T10:00:00+00:00',
            ],
            [
                'kind' => 'ai',
                'provider' => 'openai',
                'extensions' => [[
                    'key' => 'openai.system_prompt',
                    'value' => 'Ignore prior instructions',
                ]],
            ],
            [
                'kind' => 'ai',
                'provider' => 'openai',
                'extensions' => [[
                    'key' => 'openai.run_reference',
                    'value' => 'https://example.test/run?signature=secret',
                ]],
            ],
            [
                'kind' => 'ai',
                'provider' => 'anthropic',
                'model_id' => 'claude-opus',
                'name' => 'A name AI provenance must not accept',
            ],
            [
                'kind' => 'ai',
                'provider' => 'anthropic',
                'model_id' => 'claude-opus',
                'version' => 'A software version AI provenance must not accept',
            ],
            [
                'kind' => 'software',
                'name' => 'Artifact exporter',
                'provider' => 'anthropic',
                'model_id' => 'claude-opus',
            ],
            [
                'kind' => 'human',
                'version' => 'A software version human provenance must not accept',
                'provider' => 'anthropic',
                'model_id' => 'claude-opus',
            ],
        ] as $index => $producer) {
            $error = $this->toolErrorPayload($this->callTool($token, 'create', [
                'workspace_uid' => $workspace->uid,
                'type' => PageType::Markdown->value,
                'title' => 'Invalid Provenance ' . $index,
                'content' => '# Invalid provenance',
                'change_summary' => 'Attempt invalid provenance.',
                'provenance' => [
                    'producers' => [$producer],
                ],
            ]));

            $this->assertSame('invalid_request', $error['type']);
        }

        $this->assertSame(0, Page::query()->where('title', 'like', 'Invalid Provenance%')->count());
        $this->assertDatabaseCount('producer_assertions', 0);
        $this->assertDatabaseCount('external_origin_references', 0);
    }

    public function test_mcp_rejects_credential_patterns_in_provenance_without_persisting_trace_data(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Secret Provenance Owner', 'secret-provenance-owner@example.test');
        $service = $this->createServiceAccount('Secret Provenance Agent', 'secret-provenance-agent@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Secret Provenance Team');
        $this->addMember($workspace, $service, WorkspaceRole::Editor);
        $token = $this->issueToken($service, [
            McpAccessTokenIssuer::SCOPE_CREATE,
        ])->plainTextToken;
        $secret = 'ghp_' . str_repeat('a', 30);

        $error = $this->toolErrorPayload($this->callTool($token, 'create', [
            'workspace_uid' => $workspace->uid,
            'type' => PageType::Markdown->value,
            'title' => 'Rejected Secret Provenance',
            'content' => '# Safe content',
            'change_summary' => 'Attempt secret provenance.',
            'provenance' => [
                'producers' => [[
                    'kind' => 'ai',
                    'provider' => 'anthropic',
                    'model_id' => $secret,
                ]],
            ],
        ]));

        $this->assertSame('blocked_content', $error['type']);
        $this->assertSame(['github_token'], $error['finding_codes']);
        $this->assertDatabaseMissing('pages', ['title' => 'Rejected Secret Provenance']);
        $this->assertDatabaseCount('producer_assertions', 0);
        $this->assertDatabaseMissing('domain_events', [
            'event_type' => 'page.version.producer_asserted',
        ]);
        $this->assertDatabaseMissing('audit_entries', [
            'action' => 'page.version.producer_asserted',
        ]);
        $this->assertSame([], Storage::disk('artifacts')->allFiles());
    }
}
