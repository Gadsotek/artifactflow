<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Mcp\Output\McpUntrustedText;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageStatus;
use App\Models\InstallationSettings;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembershipRemoval;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class McpPassageSearchTest extends McpTestCase
{
    private const string TOOL = 'search_passages';
    private User $owner;
    private User $agent;
    private Workspace $workspace;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('artifacts');
        $this->owner = $this->createUser('Page owner', 'passage-owner@example.test');
        $this->agent = $this->createServiceAccount('Passage reader', 'passage-reader@example.test');
        $this->workspace = app(CreateSharedWorkspace::class)->handle($this->owner, 'Catalog');
        $this->addMember($this->workspace, $this->agent, WorkspaceRole::Reader);
        $this->token = $this->issueToken($this->agent, ['mcp:read', 'mcp:search'])->plainTextToken;
    }

    public function test_catalog_appends_one_tool_only_while_live_policy_allows_it_and_requires_both_scopes(): void
    {
        $baseline = $this->catalog();
        self::assertNotContains(self::TOOL, $baseline);
        $unknown = $this->callTool($this->token, self::TOOL, ['query' => 'rule'])->assertStatus(400)->json('error');
        $settings = $this->enableIndex();
        self::assertSame([...$baseline, self::TOOL], $this->catalog());
        foreach (['mcp:read', 'mcp:search'] as $scope) {
            $token = $this->issueToken($this->agent, [$scope])->plainTextToken;
            self::assertSame('insufficient_scope', $this->toolErrorPayload($this->callTool($token, self::TOOL, ['query' => 'rule']))['type']);
        }
        $settings->forceFill(['passage_retrieval_enabled' => false, 'passage_retrieval_generation' => 2, 'passage_retrieval_extractor_revision' => null])->save();
        self::assertSame($unknown, $this->callTool($this->token, self::TOOL, ['query' => 'rule'])->assertStatus(400)->json('error'));
        self::assertSame($baseline, $this->catalog());
    }

    public function test_hits_envelope_text_apply_live_filters_and_cite_the_exact_retained_version(): void
    {
        $this->enableIndex();
        $source = "# Purchasing\n\nHold the invoice until matched.";
        $category = $this->createCategory($this->workspace, $this->owner, 'Finance');
        $page = $this->createPageWithApprovedStatus($this->owner, $this->workspace, 'Invoice policy', $source, categoryUid: $category->uid, tagNames: ['tax']);
        $hits = $this->hits(['query' => 'invoice matched', 'tag_uids' => [(string) Str::ulid(), $page->tags()->sole()->uid], 'page_uid' => $page->uid, 'status' => 'approved', 'type' => 'markdown', 'owner_user_uid' => $this->owner->uid, 'category_uid' => $category->uid, 'workspace_uid' => $this->workspace->uid]);
        self::assertCount(1, $hits);
        $hit = $hits[0];
        self::assertSame($page->uid, $hit['page_uid']);
        self::assertSame($page->current_version_uid, $hit['version_uid']);
        self::assertSame(1, $hit['version_number']);
        self::assertSame((new McpUntrustedText('Invoice policy'))->toWire(), $hit['title']);
        self::assertSame([(new McpUntrustedText('Purchasing'))->toWire()], $hit['heading_path']);
        self::assertSame((new McpUntrustedText('Hold the invoice until matched.'))->toWire(), $hit['excerpt']);
        self::assertFalse($hit['index_limited']);
        $locator = $this->payloadArray($hit, 'source_locator');
        self::assertSame('markdown_source', $locator['kind']);
        self::assertSame(14, $locator['start_byte']);
        $path = route('pages.versions.show', ['page' => $page->uid, 'version' => $page->current_version_uid], absolute: false);
        self::assertSame(config()->string('app.url') . $path, $hit['citation_url']);
        foreach (['workspace_uid', 'page_uid', 'category_uid', 'owner_user_uid'] as $filter) {
            self::assertSame([], $this->hits(['query' => 'invoice', $filter => (string) Str::ulid()]));
        }

        $page->forceFill(['status' => PageStatus::Archived])->save();
        self::assertSame([], $this->hits(['query' => 'invoice']));
        self::assertCount(1, $this->hits(['query' => 'invoice', 'status' => 'archived']));
        $page->forceFill(['status' => PageStatus::Approved])->save();
        app(UpdatePageContent::class)->handle($this->owner, new UpdatePageContentCommand($page->uid, 'Different policy', baseVersionUid: $page->current_version_uid));
        self::assertSame([], $this->hits(['query' => 'invoice']));
        $this->flushHeaders();
        $this->actingAs($this->owner)->get($path)->assertOk()->assertSee('Hold the invoice until matched.');
    }

    public function test_pruning_while_disabled_retires_the_citation_and_cascades_its_index(): void
    {
        $settings = $this->enableIndex();
        $page = $this->createPageWithApprovedStatus($this->owner, $this->workspace, 'Retention', 'Original receipt policy');
        $hit = $this->hits(['query' => 'receipt'])[0];
        $url = $this->payloadString($hit, 'citation_url');

        $settings->forceFill([
            'max_page_versions' => 1,
            'passage_retrieval_enabled' => false,
            'passage_retrieval_generation' => 2,
            'passage_retrieval_extractor_revision' => null,
        ])->save();
        app(InstallationLimitSettings::class)->forgetCachedValues();
        $this->flushHeaders();
        $this->actingAs($this->owner)->get($url)->assertOk()->assertSee('Original receipt policy');
        $this->assertDatabaseHas('passage_sets', ['page_version_uid' => $page->current_version_uid]);

        app(UpdatePageContent::class)->handle($this->owner, new UpdatePageContentCommand($page->uid, 'Replacement policy', baseVersionUid: $page->current_version_uid));
        $this->get($url)->assertNotFound();
        $this->assertDatabaseMissing('page_versions', ['uid' => $page->current_version_uid]);
        $this->assertDatabaseCount('passage_sets', 0);
        $this->assertDatabaseCount('passages', 0);
    }

    public function test_exact_authorization_precedes_the_limit_and_source_availability_is_live(): void
    {
        $this->enableIndex();
        $other = app(CreateSharedWorkspace::class)->handle($this->owner, 'Former team');
        for ($i = 0; $i < 6; $i++) {
            $denied = $this->createPageWithApprovedStatus($this->owner, $other, 'Private page ' . $i, "# accounting\n\naccounting");
            $this->grantUserPageAccess($denied, $this->agent, $this->owner, WorkspaceRole::Reader);
        }
        WorkspaceMembershipRemoval::query()->create(['workspace_uid' => $other->uid, 'user_uid' => $this->agent->uid, 'removed_at' => now()->addSecond()]);
        $good = $this->createPageWithApprovedStatus($this->owner, $this->workspace, 'Reachable', 'An accounting exception');
        $result = $this->hits(['query' => 'accounting', 'limit' => 1]);
        self::assertCount(1, $result);
        self::assertSame($good->uid, $result[0]['page_uid']);
        $token = $this->issueToken($this->agent, ['mcp:search', 'mcp:read'], workspaceUids: [$this->workspace->uid])->plainTextToken;
        self::assertSame(['results' => []], $this->successfulToolPayload($this->callTool($token, self::TOOL, ['query' => 'accounting', 'workspace_uid' => $other->uid])));
        $version = $good->currentVersion()->sole();
        Storage::disk('artifacts')->put($version->content_storage_path, 'A different file');
        self::assertSame([], $this->hits(['query' => 'accounting']));
        Storage::disk('artifacts')->delete($version->content_storage_path);
        self::assertSame([], $this->hits(['query' => 'accounting']));
    }

    public function test_retiring_a_grant_before_evidence_is_loaded_drops_the_candidate(): void
    {
        $this->enableIndex();
        $other = app(CreateSharedWorkspace::class)->handle($this->owner, 'Grant workspace');
        $page = $this->createPageWithApprovedStatus($this->owner, $other, 'Granted policy', 'A receipt is required');
        $this->grantUserPageAccess($page, $this->agent, $this->owner, WorkspaceRole::Reader);
        $retired = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($other, $page, &$retired): void {
            if (!$retired && str_contains($sql, '"heading_path", "source_locator", "excerpt"')) {
                $retired = true;
                WorkspaceMembershipRemoval::query()->create(['workspace_uid' => $other->uid, 'user_uid' => $this->agent->uid, 'removed_at' => now()->addSecond()]);
                app(\App\Application\PageCatalog\PageAccessRevision::class)->bump($page);
            }
        });

        self::assertSame([], $this->hits(['query' => 'receipt']));
        self::assertTrue($retired);
    }

    public function test_search_timeout_returns_a_retryable_error_and_leaves_the_connection_usable(): void
    {
        $this->enableIndex();
        config(['database.connections.passage_search_competitor' => DB::connection()->getConfig()]);
        $competitor = DB::connection('passage_search_competitor');
        $competitor->beginTransaction();
        try {
            $competitor->statement('LOCK TABLE passages IN ACCESS EXCLUSIVE MODE');
            $error = $this->toolErrorPayload($this->callTool($this->token, self::TOOL, ['query' => 'receipt']));
            self::assertSame('temporarily_unavailable', $error['type']);
            self::assertSame(1, $error['retry_after']);
            self::assertStringNotContainsString('receipt', json_encode($error, JSON_THROW_ON_ERROR));
        } finally {
            $competitor->rollBack();
            DB::purge('passage_search_competitor');
        }
        $this->createPageWithApprovedStatus($this->owner, $this->workspace, 'Receipt policy', 'Keep the receipt.');
        self::assertCount(1, $this->hits(['query' => 'receipt']));
    }

    public function test_live_read_ceiling_denies_a_previously_indexed_version_even_with_cached_limits(): void
    {
        $settings = $this->enableIndex();
        $this->createPageWithApprovedStatus($this->owner, $this->workspace, 'Rule', 'Compare the invoice before sending payment.');
        self::assertCount(1, $this->hits(['query' => 'payment']));
        app(InstallationLimitSettings::class)->current();
        $settings->forceFill(['max_markdown_bytes' => 8, 'max_html_bytes' => 8, 'artifact_max_bytes' => 8])->save();
        self::assertSame([], $this->hits(['query' => 'payment']));
    }

    public function test_encoded_results_keep_whole_hits_within_the_budget_and_break_ties_stably(): void
    {
        $this->enableIndex();
        $ids = [];
        $heading = str_repeat('広', 1100);
        $body = 'invoice ' . str_repeat('€', 1220);
        for ($i = 0; $i < 10; $i++) {
            $ids[] = $this->createPageWithApprovedStatus($this->owner, $this->workspace, 'Guide ' . $i, "# $heading\n\n$body")->uid;
        }
        $default = $this->hits(['query' => 'invoice']);
        self::assertCount(5, $default);
        sort($ids, SORT_STRING);
        self::assertSame(array_slice($ids, 0, 5), array_column($default, 'page_uid'));
        $hits = $this->hits(['query' => 'invoice', 'limit' => 10]);
        self::assertGreaterThanOrEqual(5, count($hits));
        self::assertLessThan(10, count($hits));
        self::assertLessThanOrEqual(65536, strlen(json_encode(['results' => $hits], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        foreach ($hits as $hit) {
            self::assertSame((new McpUntrustedText($body))->toWire(), $hit['excerpt']);
            self::assertSame([(new McpUntrustedText($heading))->toWire()], $hit['heading_path']);
        }
    }

    public function test_query_and_filters_have_bounded_strict_input(): void
    {
        $this->enableIndex();
        foreach ([[], ['query' => '  '], ['query' => str_repeat('x', 201)], ['limit' => '3'], ['limit' => 0], ['limit' => 11], ['status' => 'unknown'], ['status' => null], ['type' => '  '], ['type' => 'pdf'], ['page_uid' => 'invalid'], ['tag_uids' => 'invalid'], ['tag_uids' => ['invalid']], ['tag_uids' => array_fill(0, 101, (string) Str::ulid())]] as $index => $arguments) {
            if ($index !== 0) {
                $arguments += ['query' => 'rule'];
            }
            $error = $this->toolErrorPayload($this->callTool($this->token, self::TOOL, $arguments));
            self::assertSame('invalid_request', $error['type']);
        }
    }

    /** @return list<mixed> */
    private function catalog(): array
    {
        $this->flushHeaders();
        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $this->token, 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/list'])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 'list', 'method' => 'tools/list', 'params' => ['per_page' => 50, '_meta' => $this->modernMcpMetadata()]])->assertOk();
        $tools = $response->json('result.tools');
        self::assertIsArray($tools);

        return array_column($tools, 'name');
    }

    /**
     * @phpstan-impure
     * @param array<string, mixed> $input
     * @return list<array<string, mixed>>
     */
    private function hits(array $input): array
    {
        return $this->payloadList($this->successfulToolPayload($this->callTool($this->token, self::TOOL, $input)), 'results');
    }

    private function enableIndex(): InstallationSettings
    {
        return InstallationSettings::query()->forceCreate(app(InstallationLimitSettings::class)->current()->toPersistenceArray() + [
            'passage_retrieval_enabled' => true, 'passage_retrieval_generation' => 1, 'passage_retrieval_extractor_revision' => 1,
        ]);
    }
}
