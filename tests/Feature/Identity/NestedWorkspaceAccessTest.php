<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\WorkspaceContext;
use App\Application\Mcp\McpRequestContext;
use App\Application\Mcp\McpWorkspaceListing;
use App\Application\PageCatalog\PageAccess;
use App\Application\PageCatalog\PageSearch;
use App\Application\PageCatalog\PageSearchFilters;
use App\Application\PageCatalog\PageSearchSort;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageStatus;
use App\Models\McpAccessToken;
use App\Models\Page;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class NestedWorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_inherited_membership_drives_page_access_navigation_and_search(): void
    {
        [$owner, $root, $child, $grandchild] = $this->threeLevelHierarchy();
        $reader = $this->createUser('Root Reader', 'root-reader@example.test');
        $this->addMember($root, $reader, WorkspaceRole::Reader);
        $page = Page::factory()->create([
            'workspace_uid' => $grandchild->uid,
            'owner_user_uid' => $owner->uid,
            'title' => 'Inherited Grandchild Page',
            'access_mode' => PageAccessMode::Inherited,
            'status' => PageStatus::Draft,
        ]);

        $access = app(PageAccess::class);

        $this->assertTrue($access->canView($reader, $page));
        $this->assertFalse($access->canEdit($reader, $page));
        $this->assertEqualsCanonicalizing(
            [$root->uid, $child->uid, $grandchild->uid],
            array_map(
                static fn ($item): string => $item->uid,
                app(WorkspaceContext::class)->itemsFor($reader),
            ),
        );

        $results = app(PageSearch::class)->search($reader, new PageSearchFilters(
            query: null,
            workspaceUid: PageSearchFilters::ALL_WORKSPACES,
            type: null,
            statuses: PageSearchFilters::activeStatuses(),
            categoryUids: [],
            tagUids: [],
            ownerUserUid: null,
            sort: PageSearchSort::RecentlyUpdated,
        ));

        $this->assertSame([$page->uid], array_map(
            static fn ($result): string => $result->page->uid,
            $results,
        ));
    }

    public function test_selected_mcp_scope_is_exact_while_child_scope_can_use_an_inherited_role(): void
    {
        [$owner, $root, $child] = $this->threeLevelHierarchy();
        $editor = $this->createUser('Root Editor', 'root-editor@example.test');
        $this->addMember($root, $editor, WorkspaceRole::Editor);
        $page = Page::factory()->create([
            'workspace_uid' => $child->uid,
            'owner_user_uid' => $owner->uid,
            'access_mode' => PageAccessMode::Inherited,
        ]);
        $parentToken = $this->token($editor, [$root->uid]);
        $childToken = $this->token($editor, [$child->uid]);
        $context = app(McpRequestContext::class);
        $access = app(PageAccess::class);

        $context->activate($parentToken, 'parent-scope');
        $this->assertFalse($access->canView($editor, $page));
        $this->assertSame([$root->uid], $this->listedWorkspaceUids($editor, $parentToken));

        $context->clear();
        $access->flushCache();
        $context->activate($childToken, 'child-scope');
        $this->assertTrue($access->canView($editor, $page));
        $this->assertTrue($access->canEdit($editor, $page));
        $this->assertSame([$child->uid], $this->listedWorkspaceUids($editor, $childToken));
        $context->clear();
    }

    /**
     * @return array{User, Workspace, Workspace, Workspace}
     */
    private function threeLevelHierarchy(): array
    {
        $owner = $this->createUser('Nested Workspace Owner', 'nested-workspace-owner@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($owner, 'Root');
        $child = app(CreateSharedWorkspace::class)->handle($owner, 'Child');
        $grandchild = app(CreateSharedWorkspace::class)->handle($owner, 'Grandchild');

        Workspace::query()->whereKey($child->uid)->update(['parent_workspace_uid' => $root->uid]);
        Workspace::query()->whereKey($grandchild->uid)->update(['parent_workspace_uid' => $child->uid]);
        DB::table('workspace_ancestry')->insert([
            [
                'ancestor_workspace_uid' => $root->uid,
                'descendant_workspace_uid' => $child->uid,
                'depth' => 1,
            ],
            [
                'ancestor_workspace_uid' => $child->uid,
                'descendant_workspace_uid' => $grandchild->uid,
                'depth' => 1,
            ],
            [
                'ancestor_workspace_uid' => $root->uid,
                'descendant_workspace_uid' => $grandchild->uid,
                'depth' => 2,
            ],
        ]);

        return [$owner, $root, $child, $grandchild];
    }

    private function createUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    private function addMember(Workspace $workspace, User $user, WorkspaceRole $role): void
    {
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $user->uid,
            'role' => $role,
            'accepted_at' => now(),
        ]);
    }

    /**
     * @param list<string> $workspaceUids
     */
    private function token(User $principal, array $workspaceUids): McpAccessToken
    {
        return McpAccessToken::query()->forceCreate([
            'principal_user_uid' => $principal->uid,
            'name' => 'Nested workspace scope',
            'token_hash' => hash('sha256', implode(':', $workspaceUids)),
            'scopes' => ['mcp:search', 'mcp:read'],
            'workspace_uids' => $workspaceUids,
            'expires_at' => now()->addHour(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function listedWorkspaceUids(User $actor, McpAccessToken $token): array
    {
        return array_map(
            static fn (array $workspace): string => $workspace['uid'],
            app(McpWorkspaceListing::class)->forActor($actor, $token),
        );
    }
}
