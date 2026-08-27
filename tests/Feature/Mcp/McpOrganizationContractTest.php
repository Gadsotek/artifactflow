<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageType;
use App\Models\DomainEvent;
use App\Models\McpAccessToken;
use App\Models\Page;
use Illuminate\Support\Facades\Storage;

final class McpOrganizationContractTest extends McpTestCase
{
    public function test_mcp_create_assigns_a_real_parent_and_organize_updates_only_organizational_metadata(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Organization Owner', 'mcp-organization-owner@example.test');
        $service = $this->createServiceAccount('Organization Agent', 'mcp-organization-agent@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Organization Team');
        $this->addMember($workspace, $service, WorkspaceRole::Editor);
        $parent = $this->createPageWithApprovedStatus(
            actor: $owner,
            workspace: $workspace,
            title: 'Organization Parent',
            content: '# Parent',
        );
        $category = $this->createCategory($workspace, $owner, 'Organized Runbooks');
        $token = $this->issueToken($service, [
            McpAccessTokenIssuer::SCOPE_CREATE,
            McpAccessTokenIssuer::SCOPE_ORGANIZE,
        ])->plainTextToken;

        $created = $this->successfulToolPayload($this->callTool($token, 'create', [
            'workspace_uid' => $workspace->uid,
            'type' => PageType::Markdown->value,
            'title' => 'Organization Child',
            'description' => 'Description must remain unchanged.',
            'content' => '# Child',
            'parent_page_uid' => $parent->uid,
            'change_summary' => 'Create a real child page.',
        ]));
        $page = Page::query()->whereKey($this->payloadString($created, 'uid'))->sole();

        $this->assertSame($parent->uid, $page->parent_page_uid);
        $this->assertSame(0, $page->metadata_revision);

        $organized = $this->successfulToolPayload($this->callTool($token, 'organize', [
            'page_uid' => $page->uid,
            'expected_metadata_revision' => 0,
            'title' => 'Organized Child',
            'parent_page_uid' => null,
            'category_uid' => $category->uid,
            'tags' => ['Operations', 'Runbook'],
        ]));
        $page->refresh();

        $this->assertSame(1, $organized['metadata_revision']);
        $this->assertSame('Organized Child', $page->title);
        $this->assertNull($page->parent_page_uid);
        $this->assertSame($category->uid, $page->category_uid);
        $this->assertSame(['operations', 'runbook'], $page->tags()->orderBy('name')->pluck('name')->all());
        $this->assertSame('Description must remain unchanged.', $page->description);
        $this->assertSame($service->uid, $page->owner_user_uid);
        $event = DomainEvent::query()
            ->where('event_type', 'page.metadata.updated')
            ->where('aggregate_uid', $page->uid)
            ->sole();
        $this->assertSame(
            McpAccessToken::query()->where('principal_user_uid', $service->uid)->sole()->uid,
            $event->payload['mcp_access_token_uid'] ?? null,
        );
        $this->assertSame('test-session', $event->payload['mcp_agent_session_id'] ?? null);
    }

    public function test_mcp_organize_requires_its_scope_and_rejects_stale_or_cyclic_hierarchy_changes(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Organization Guard Owner', 'mcp-organization-guard-owner@example.test');
        $service = $this->createServiceAccount('Organization Guard Agent', 'mcp-organization-guard-agent@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Organization Guard Team');
        $this->addMember($workspace, $service, WorkspaceRole::Editor);
        $parent = $this->createPageWithApprovedStatus(
            actor: $owner,
            workspace: $workspace,
            title: 'Guard Parent',
            content: '# Parent',
        );
        $child = $this->createPageWithApprovedStatus(
            actor: $owner,
            workspace: $workspace,
            title: 'Guard Child',
            content: '# Child',
            parentPageUid: $parent->uid,
        );
        $createOnly = $this->issueToken($service, [
            McpAccessTokenIssuer::SCOPE_CREATE,
        ])->plainTextToken;

        $missingScope = $this->toolErrorPayload($this->callTool($createOnly, 'organize', [
            'page_uid' => $child->uid,
            'expected_metadata_revision' => 0,
            'parent_page_uid' => null,
        ]));
        $inlineTaxonomy = $this->toolErrorPayload($this->callTool($createOnly, 'create', [
            'workspace_uid' => $workspace->uid,
            'type' => PageType::Markdown->value,
            'title' => 'Create-only Taxonomy Attempt',
            'content' => '# No taxonomy authority',
            'category_name' => 'Must Not Be Created',
            'tags' => ['must-not-be-created'],
            'change_summary' => 'Attempt implicit taxonomy creation.',
        ]));

        $this->assertSame('insufficient_scope', $missingScope['type']);
        $this->assertSame('insufficient_scope', $inlineTaxonomy['type']);
        $this->assertDatabaseMissing('categories', ['name' => 'Must Not Be Created']);
        $this->assertDatabaseMissing('tags', ['slug' => 'must-not-be-created']);

        $organizeToken = $this->issueToken($service, [
            McpAccessTokenIssuer::SCOPE_ORGANIZE,
        ])->plainTextToken;
        $cycle = $this->toolErrorPayload($this->callTool($organizeToken, 'organize', [
            'page_uid' => $parent->uid,
            'expected_metadata_revision' => 0,
            'parent_page_uid' => $child->uid,
        ]));

        $this->assertSame('invalid_request', $cycle['type']);
        $this->assertNull($parent->refresh()->parent_page_uid);

        $this->successfulToolPayload($this->callTool($organizeToken, 'organize', [
            'page_uid' => $child->uid,
            'expected_metadata_revision' => 0,
            'title' => 'Current Child Title',
        ]));
        $stale = $this->toolErrorPayload($this->callTool($organizeToken, 'organize', [
            'page_uid' => $child->uid,
            'expected_metadata_revision' => 0,
            'title' => 'Stale Child Title',
        ]));

        $this->assertSame('conflict', $stale['type']);
        $this->assertTrue($stale['retryable']);
        $this->assertSame(1, $stale['current_metadata_revision']);
        $this->assertSame('Current Child Title', $child->refresh()->title);
    }

    public function test_workspace_scoped_tokens_cannot_create_outside_their_scope(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Create Scope Owner', 'create-scope-owner@example.test');
        $service = $this->createServiceAccount('Create Scope Agent', 'create-scope-agent@example.test');
        $alphaWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Create Alpha Team');
        $betaWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Create Beta Team');
        $this->addMember($alphaWorkspace, $service, WorkspaceRole::Editor);
        $this->addMember($betaWorkspace, $service, WorkspaceRole::Editor);
        $token = $this->issueToken(
            principal: $service,
            scopes: [McpAccessTokenIssuer::SCOPE_CREATE],
            workspaceUids: [$alphaWorkspace->uid],
        )->plainTextToken;

        $this->assertSame(['type' => 'not_found', 'message' => 'Workspace not found.'], $this->toolErrorPayload(
            $this->callTool($token, 'create', [
                'workspace_uid' => $betaWorkspace->uid,
                'type' => 'markdown',
                'title' => 'Out of scope page',
                'content' => '# Out of scope',
                'change_summary' => 'Attempt an out-of-scope creation.',
            ]),
        ));
        $this->assertSame(0, Page::query()->where('workspace_uid', $betaWorkspace->uid)->count());
    }
}
