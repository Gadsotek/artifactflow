<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Application\Identity\WorkspaceContext;
use App\Application\Identity\WorkspaceMemberOverview;
use App\Application\PageCatalog\PageHierarchy;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Guards the hot render paths (workspace nav, member overview, page hierarchy)
 * against N+1 regressions. Each test measures the query count at two very
 * different item counts and asserts it does not grow -- the count is a small
 * constant regardless of how many members/children/workspaces are rendered.
 */
final class HotPathQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_overview_query_count_does_not_grow_with_member_count(): void
    {
        $overview = app(WorkspaceMemberOverview::class);

        [$smallActor, $smallWorkspace] = $this->workspaceWithMembers(2, 2);
        [$largeActor, $largeWorkspace] = $this->workspaceWithMembers(9, 2);

        $small = $this->measureQueries(static fn () => $overview->forWorkspace($smallActor, $smallWorkspace->uid));
        $large = $this->measureQueries(static fn () => $overview->forWorkspace($largeActor, $largeWorkspace->uid));

        $this->assertSame($small, $large, "member overview scaled queries: 2 members={$small}, 9 members={$large}");
    }

    public function test_workspace_nav_query_count_does_not_grow_with_workspace_count(): void
    {
        $context = app(WorkspaceContext::class);

        $smallUser = $this->userInWorkspaces(2);
        $largeUser = $this->userInWorkspaces(9);

        $small = $this->measureQueries(static fn () => $context->itemsFor($smallUser));
        $large = $this->measureQueries(static fn () => $context->itemsFor($largeUser));

        $this->assertSame($small, $large, "workspace nav scaled queries: 2 workspaces={$small}, 9 workspaces={$large}");
    }

    public function test_page_hierarchy_query_count_does_not_grow_with_child_count(): void
    {
        $hierarchy = app(PageHierarchy::class);

        [$smallActor, $smallParent] = $this->parentWithRestrictedChildren(2);
        [$largeActor, $largeParent] = $this->parentWithRestrictedChildren(9);

        $small = $this->measureQueries(static fn () => $hierarchy->forPage($smallActor, $smallParent));
        $large = $this->measureQueries(static fn () => $hierarchy->forPage($largeActor, $largeParent));

        $this->assertSame($small, $large, "page hierarchy scaled queries: 2 children={$small}, 9 children={$large}");
    }

    private function measureQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function workspaceWithMembers(int $memberCount, int $pagesPerMember): array
    {
        $workspace = $this->sharedWorkspace();
        $actor = null;

        for ($i = 0; $i < $memberCount; $i++) {
            $user = User::factory()->create();
            $actor ??= $user;
            $this->addMember($workspace, $user, WorkspaceRole::Editor);

            for ($p = 0; $p < $pagesPerMember; $p++) {
                $this->page($workspace, $user, PageAccessMode::Inherited);
            }
        }

        return [$actor ?? User::factory()->create(), $workspace];
    }

    private function userInWorkspaces(int $workspaceCount): User
    {
        $user = User::factory()->create();

        for ($i = 0; $i < $workspaceCount; $i++) {
            $this->addMember($this->sharedWorkspace(), $user, WorkspaceRole::Editor);
        }

        return $user;
    }

    /**
     * @return array{0: User, 1: Page}
     */
    private function parentWithRestrictedChildren(int $childCount): array
    {
        $workspace = $this->sharedWorkspace();
        $actor = User::factory()->create();
        // A reader role does not itself grant access to restricted pages, so
        // canView() falls through to the per-page grant check we are guarding.
        $this->addMember($workspace, $actor, WorkspaceRole::Reader);

        $owner = User::factory()->create();
        $this->addMember($workspace, $owner, WorkspaceRole::Editor);
        $parent = $this->page($workspace, $owner, PageAccessMode::Inherited);

        for ($i = 0; $i < $childCount; $i++) {
            $child = $this->page($workspace, $owner, PageAccessMode::Restricted, $parent->uid);
            PageAccessGrant::query()->forceCreate([
                'page_uid' => $child->uid,
                'subject_type' => PageAccessSubjectType::User,
                'subject_uid' => $actor->uid,
                'role' => WorkspaceRole::Reader,
                'granted_by_user_uid' => $owner->uid,
            ]);
        }

        return [$actor, $parent];
    }

    private function sharedWorkspace(): Workspace
    {
        return Workspace::query()->forceCreate([
            'name' => 'Workspace ' . Str::lower(Str::random(8)),
            'type' => WorkspaceType::Shared,
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

    private function page(
        Workspace $workspace,
        User $owner,
        PageAccessMode $accessMode,
        ?string $parentPageUid = null,
    ): Page {
        return Page::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'owner_user_uid' => $owner->uid,
            'parent_page_uid' => $parentPageUid,
            'title' => 'Page ' . Str::lower(Str::random(8)),
            'slug' => 'page-' . Str::lower(Str::random(10)),
            'type' => PageType::Markdown,
            'status' => PageStatus::Draft,
            'access_mode' => $accessMode,
        ]);
    }
}
