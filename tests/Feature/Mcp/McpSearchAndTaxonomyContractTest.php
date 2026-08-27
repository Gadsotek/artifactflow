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
use App\Models\Tag;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class McpSearchAndTaxonomyContractTest extends McpTestCase
{
    public function test_search_scope_snippets_and_argument_validation_paths(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Search Owner', 'search-coverage-owner@example.test');
        $service = $this->createServiceAccount('Search Agent', 'search-coverage-agent@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Search Coverage Team');
        $this->addMember($workspace, $service, WorkspaceRole::Editor);
        $page = $this->createPageWithApprovedStatus(
            actor: $owner,
            workspace: $workspace,
            title: 'Snippet Needle',
            content: '# Snippet Needle body',
        );
        $searchOnlyToken = $this->issueToken($service, ['mcp:search'])->plainTextToken;
        $searchReadToken = $this->issueToken($service, ['mcp:search', 'mcp:read'])->plainTextToken;

        $withSnippet = $this->successfulToolPayload($this->callTool($searchReadToken, 'search', [
            'query' => 'Needle',
            'include_snippet' => true,
            'workspace_uid' => $workspace->uid,
            'type' => 'markdown',
            'status' => 'approved',
            'sort' => 'title',
        ]));
        $results = $this->payloadList($withSnippet, 'results');

        $this->assertSame($page->uid, $results[0]['uid']);
        $this->assertArrayHasKey('snippet', $results[0]);
        $this->assertSame('insufficient_scope', $this->toolErrorPayload($this->callTool($searchOnlyToken, 'search', [
            'include_snippet' => true,
        ]))['type']);
        $this->assertSame('invalid_request', $this->toolErrorPayload($this->callTool($searchOnlyToken, 'search', [
            'include_archived' => 'maybe',
        ]))['type']);
        $this->assertSame('invalid_request', $this->toolErrorPayload($this->callTool($searchOnlyToken, 'search', [
            'tag_uids' => 'not-a-list',
        ]))['type']);
        $this->assertSame('invalid_request', $this->toolErrorPayload($this->callTool($searchOnlyToken, 'search', [
            'tag_uids' => ['ok', 123],
        ]))['type']);
        $this->successfulToolPayload($this->callTool($searchOnlyToken, 'search', [
            'tag_uids' => ['not-a-ulid'],
        ]));
        $this->successfulToolPayload($this->callTool($searchOnlyToken, 'search', [
            'tag_uids' => [...array_fill(0, 21, $workspace->uid), '', '   '],
        ]));
        $this->successfulToolPayload($this->callTool($searchOnlyToken, 'search', [
            'tag_uids' => array_map(
                static fn (int $index): string => 'tag-' . $index,
                range(1, 21),
            ),
        ]));
        $this->assertSame('invalid_request', $this->toolErrorPayload($this->callTool($searchOnlyToken, 'search', [
            'query' => ['structured'],
        ]))['type']);
        $this->assertSame('invalid_request', $this->toolErrorPayload($this->callTool($searchOnlyToken, 'search', [
            'type' => 'unsupported',
        ]))['type']);
        $this->assertSame('invalid_request', $this->toolErrorPayload($this->callTool($searchOnlyToken, 'search', [
            'status' => 'unsupported',
        ]))['type']);
    }

    public function test_mcp_taxonomy_discovery_is_searchable_and_token_workspace_scoped(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Taxonomy Owner', 'taxonomy-owner@example.test');
        $service = $this->createServiceAccount('Taxonomy Agent', 'taxonomy-agent@example.test');
        $alphaWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Alpha Team');
        $betaWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Beta Team');
        $this->addMember($alphaWorkspace, $service, WorkspaceRole::Editor);
        $this->addMember($betaWorkspace, $service, WorkspaceRole::Editor);
        $alphaCategory = $this->createCategory($alphaWorkspace, $owner, 'Alpha Runbooks');
        $betaCategory = $this->createCategory($betaWorkspace, $owner, 'Beta Secrets');
        $alphaPage = $this->createPageWithApprovedStatus(
            actor: $owner,
            workspace: $alphaWorkspace,
            title: 'Alpha Taxonomy Page',
            content: '# Alpha',
            categoryUid: $alphaCategory->uid,
            tagNames: ['shared-taxonomy'],
        );
        $this->createPageWithApprovedStatus(
            actor: $owner,
            workspace: $betaWorkspace,
            title: 'Beta Taxonomy Page',
            content: '# Beta',
            categoryUid: $betaCategory->uid,
            tagNames: ['shared-taxonomy', 'beta-secret-tag'],
        );
        $token = $this->issueToken(
            principal: $service,
            scopes: [McpAccessTokenIssuer::SCOPE_SEARCH],
            workspaceUids: [$alphaWorkspace->uid],
        )->plainTextToken;
        $readOnlyToken = $this->issueToken(
            principal: $service,
            scopes: [McpAccessTokenIssuer::SCOPE_READ],
            workspaceUids: [$alphaWorkspace->uid],
        )->plainTextToken;

        $taxonomy = $this->successfulToolPayload($this->callTool($token, 'list_taxonomy'));
        $categories = $this->payloadList($taxonomy, 'categories');
        $tags = $this->payloadList($taxonomy, 'tags');
        $taxonomyJson = json_encode($taxonomy, JSON_THROW_ON_ERROR);

        $this->assertSame([$alphaCategory->uid], array_column($categories, 'uid'));
        $this->assertSame($alphaWorkspace->uid, $categories[0]['workspace_uid']);
        $this->assertSame('Alpha Runbooks', $this->payloadString($this->payloadArray($categories[0], 'name'), 'data'));
        $this->assertSame('alpha-runbooks', $this->payloadString($this->payloadArray($categories[0], 'slug'), 'data'));
        $this->assertSame('Alpha Team', $this->payloadString($this->payloadArray($categories[0], 'workspace_name'), 'data'));
        $tagSlug = $this->payloadArray($tags[0], 'slug');
        $this->assertSame('artifactflow.untrusted_data', $tagSlug['kind']);
        $this->assertSame('shared-taxonomy', $this->payloadString($tagSlug, 'data'));
        $this->assertStringNotContainsString('Beta Secrets', $taxonomyJson);
        $this->assertStringNotContainsString('beta-secret-tag', $taxonomyJson);
        $this->assertSame('insufficient_scope', $this->toolErrorPayload(
            $this->callTool($readOnlyToken, 'list_taxonomy'),
        )['type']);
        $this->assertSame('invalid_request', $this->toolErrorPayload($this->callTool($token, 'list_taxonomy', [
            'workspace_uid' => ['not-a-string'],
        ]))['type']);

        $tag = Tag::query()->where('slug', 'shared-taxonomy')->sole();
        $results = $this->payloadList($this->successfulToolPayload($this->callTool($token, 'search', [
            'category_uid' => $alphaCategory->uid,
            'tag_uids' => [$tag->uid],
        ])), 'results');
        $this->assertSame([$alphaPage->uid], array_column($results, 'uid'));
    }

    public function test_mcp_can_create_taxonomy_with_a_page_or_as_standalone_records(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Taxonomy Writer', 'taxonomy-writer@example.test');
        $service = $this->createServiceAccount('Taxonomy Writer Agent', 'taxonomy-writer-agent@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Taxonomy Writer Team');
        $foreignWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Foreign Taxonomy Team');
        $this->addMember($workspace, $service, WorkspaceRole::Editor);
        $this->addMember($foreignWorkspace, $service, WorkspaceRole::Editor);
        $token = $this->issueToken(
            principal: $service,
            scopes: [
                McpAccessTokenIssuer::SCOPE_CREATE,
                McpAccessTokenIssuer::SCOPE_ORGANIZE,
                McpAccessTokenIssuer::SCOPE_SEARCH,
            ],
            workspaceUids: [$workspace->uid],
        )->plainTextToken;

        $createdPagePayload = $this->successfulToolPayload($this->callTool($token, 'create', [
            'workspace_uid' => $workspace->uid,
            'type' => PageType::Markdown->value,
            'title' => 'Taxonomy Created Page',
            'content' => '# Taxonomy Created Page',
            'change_summary' => 'Create the taxonomy example.',
            'category_name' => 'Generated Runbooks',
            'tags' => ['Generated Tag'],
        ]));
        $createdPage = Page::query()->whereKey($this->payloadString($createdPagePayload, 'uid'))->sole();

        $this->assertSame('Generated Runbooks', $createdPage->category?->name);
        $this->assertSame(['generated tag'], $createdPage->tags()->pluck('name')->all());

        $categoryPayload = $this->successfulToolPayload($this->callTool($token, 'create_category', [
            'workspace_uid' => $workspace->uid,
            'name' => 'Architecture Decisions',
        ]));
        $categoryName = $this->payloadArray($categoryPayload, 'name');
        $categorySlug = $this->payloadArray($categoryPayload, 'slug');
        $this->assertSame('Architecture Decisions', $this->payloadString($categoryName, 'data'));
        $this->assertSame('architecture-decisions', $this->payloadString($categorySlug, 'data'));
        $this->assertSame($workspace->uid, $categoryPayload['workspace_uid']);

        $tagPayload = $this->successfulToolPayload($this->callTool($token, 'create_tag', [
            'workspace_uid' => $workspace->uid,
            'name' => 'Operations',
        ]));
        $tagName = $this->payloadArray($tagPayload, 'name');
        $tagSlug = $this->payloadArray($tagPayload, 'slug');
        $this->assertSame('operations', $this->payloadString($tagName, 'data'));
        $this->assertSame('operations', $this->payloadString($tagSlug, 'data'));
        $this->assertSame($workspace->uid, $tagPayload['authority_workspace_uid']);
        $this->assertArrayNotHasKey('workspace_uid', $tagPayload);
        $this->assertSame(1, Tag::query()->where('slug', 'operations')->count());
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'tag.created')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'tag.created')->count());

        $resolvedTagPayload = $this->successfulToolPayload($this->callTool($token, 'create_tag', [
            'workspace_uid' => $workspace->uid,
            'name' => 'Operations',
        ]));
        $this->assertSame($tagPayload['uid'], $resolvedTagPayload['uid']);
        $this->assertSame(1, Tag::query()->where('slug', 'operations')->count());
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'tag.created')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'tag.created')->count());

        $this->assertSame(['type' => 'not_found', 'message' => 'Workspace not found.'], $this->toolErrorPayload(
            $this->callTool($token, 'create_category', [
                'workspace_uid' => $foreignWorkspace->uid,
                'name' => 'Out of Scope Category',
            ]),
        ));
        $this->assertSame(['type' => 'not_found', 'message' => 'Workspace not found.'], $this->toolErrorPayload(
            $this->callTool($token, 'create_tag', [
                'workspace_uid' => $foreignWorkspace->uid,
                'name' => 'Out of Scope Tag',
            ]),
        ));
    }

    public function test_mcp_standalone_tag_creation_reauthorizes_after_the_workspace_lock(): void
    {
        $owner = $this->createUser('Taxonomy Lock Owner', 'taxonomy-lock-owner@example.test');
        $service = $this->createServiceAccount('Taxonomy Lock Agent', 'taxonomy-lock-agent@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Taxonomy Lock Team');
        $this->addMember($workspace, $service, WorkspaceRole::Editor);
        $membership = WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $service->uid)
            ->sole();
        $token = $this->issueToken(
            principal: $service,
            scopes: [McpAccessTokenIssuer::SCOPE_ORGANIZE],
            workspaceUids: [$workspace->uid],
        )->plainTextToken;
        $downgraded = false;

        DB::listen(function (QueryExecuted $query) use (&$downgraded, $workspace, $membership): void {
            if ($downgraded) {
                return;
            }

            $sql = strtolower($query->sql);
            if (!str_contains($sql, 'for update') || !str_contains($sql, '"workspaces"')) {
                return;
            }

            if (!in_array($workspace->uid, $query->bindings, true)) {
                return;
            }

            $downgraded = true;
            DB::table('workspace_memberships')
                ->where('uid', $membership->uid)
                ->update(['role' => WorkspaceRole::Reader->value]);
        });

        $response = $this->callTool($token, 'create_tag', [
            'workspace_uid' => $workspace->uid,
            'name' => 'Revoked MCP Tag',
        ]);

        $this->assertTrue($downgraded, 'Standalone MCP tag creation must lock the authority workspace.');
        $this->assertSame(
            ['type' => 'not_found', 'message' => 'Workspace not found.'],
            $this->toolErrorPayload($response),
        );
        $this->assertSame(0, Tag::query()->where('slug', 'revoked-mcp-tag')->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'tag.created')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'tag.created')->count());
    }
}
