<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\GrantPageAccess;
use App\Application\PageCatalog\GrantPageAccessCommand;
use App\Application\PageCatalog\PageHierarchyPresenter;
use App\Application\PageCatalog\PageSearch;
use App\Application\PageCatalog\PageSearchFilters;
use App\Application\PageCatalog\PageSearchSort;
use App\Application\PageCatalog\PageTreeItem;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A page whose parent is absent from the listing must not silently render as a
 * root: that makes a nested page look top-level. It reattaches to the nearest
 * ancestor the actor can see -- but only when every ancestor in between is one
 * the actor could have seen anyway, so an inaccessible page's existence and
 * position stay undisclosed.
 */
final class PageTreeReattachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_archived_ancestor_keeps_descendants_under_the_nearest_visible_ancestor(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Team');
        $root = $this->createPage($owner, $workspace->uid, 'Hello World Markdown');
        $archived = $this->createPage($owner, $workspace->uid, 'Archived Middle', $root->uid);
        $child = $this->createPage($owner, $workspace->uid, 'Visible Child', $archived->uid);
        $grandchild = $this->createPage($owner, $workspace->uid, 'Visible Grandchild', $child->uid);

        $archived->forceFill(['status' => PageStatus::Archived])->save();

        $items = $this->arrange($owner, $workspace->uid);

        // The archived page is filtered out of the listing, so its child would
        // otherwise be promoted to a root and read as a top-level page.
        $this->assertNotContains($archived->uid, $this->uids($items));
        $this->assertSame(0, $this->depthOf($items, $root->uid));
        $this->assertSame(1, $this->depthOf($items, $child->uid));
        $this->assertSame(2, $this->depthOf($items, $grandchild->uid));
        $this->assertSame('Hello World Markdown', $this->parentTitleOf($items, $child->uid));
    }

    public function test_inaccessible_ancestor_leaves_descendants_as_roots_without_disclosing_the_chain(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner', 'owner@example.test');
        $viewer = $this->createUser('Viewer', 'viewer@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Security Team');

        // The viewer stays outside the page workspace so it reaches only the pages
        // explicitly granted to it, never the chain by workspace inheritance.
        $sharedWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Shared Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $sharedWorkspace->uid,
            'user_uid' => $viewer->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);

        $root = $this->createPage($owner, $workspace->uid, 'Visible Root');
        $secret = $this->createPage($owner, $workspace->uid, 'Restricted Middle', $root->uid);
        $child = $this->createPage($owner, $workspace->uid, 'Granted Child', $secret->uid);

        foreach ([$root, $secret, $child] as $restricted) {
            $restricted->forceFill(['access_mode' => PageAccessMode::Restricted])->save();
        }

        foreach ([$root, $child] as $granted) {
            app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
                pageUid: $granted->uid,
                subjectType: PageAccessSubjectType::User,
                subjectUid: $viewer->uid,
                role: WorkspaceRole::Reader,
            ));
        }

        $items = $this->arrange($viewer, $workspace->uid);

        // Reattaching the child under the root would reveal that it descends from
        // the root through a page the viewer may not see. It stays a root instead.
        $this->assertSame(0, $this->depthOf($items, $root->uid));
        $this->assertSame(0, $this->depthOf($items, $child->uid));
        $this->assertNull($this->parentTitleOf($items, $child->uid));
        $this->assertNotContains($secret->uid, $this->uids($items));
    }

    public function test_visible_direct_parent_is_arranged_without_reattachment(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Team');
        $root = $this->createPage($owner, $workspace->uid, 'Root Page');
        $child = $this->createPage($owner, $workspace->uid, 'Child Page', $root->uid);

        $items = $this->arrange($owner, $workspace->uid);

        $this->assertSame(0, $this->depthOf($items, $root->uid));
        $this->assertSame(1, $this->depthOf($items, $child->uid));
        $this->assertSame('Root Page', $this->parentTitleOf($items, $child->uid));
    }

    public function test_ancestor_walk_terminates_when_stored_rows_contain_a_cycle(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Team');
        $first = $this->createPage($owner, $workspace->uid, 'Cycle One');
        $second = $this->createPage($owner, $workspace->uid, 'Cycle Two', $first->uid);
        $child = $this->createPage($owner, $workspace->uid, 'Cycle Child', $second->uid);

        // Close the cycle behind the application's back, then archive both members so
        // the walk has to traverse them: first -> second -> first.
        Page::query()->whereKey($first->uid)->update([
            'parent_page_uid' => $second->uid,
            'status' => PageStatus::Archived->value,
        ]);
        Page::query()->whereKey($second->uid)->update(['status' => PageStatus::Archived->value]);

        $items = $this->arrange($owner, $workspace->uid);

        // No hang, no duplicate: the visible child renders exactly once as a root.
        $this->assertSame([$child->uid], $this->uids($items));
        $this->assertSame(0, $this->depthOf($items, $child->uid));
    }

    /**
     * @return list<PageTreeItem>
     */
    private function arrange(User $actor, string $workspaceUid): array
    {
        $filters = new PageSearchFilters(
            query: null,
            workspaceUid: $workspaceUid,
            type: null,
            status: null,
            categoryUid: null,
            tagUids: [],
            ownerUserUid: null,
            includeArchived: false,
            sort: PageSearchSort::Title,
        );

        return app(PageHierarchyPresenter::class)->arrange(
            $actor,
            app(PageSearch::class)->search($actor, $filters),
        );
    }

    /**
     * @param list<PageTreeItem> $items
     * @return list<string>
     */
    private function uids(array $items): array
    {
        return array_map(static fn (PageTreeItem $item): string => $item->result->page->uid, $items);
    }

    /**
     * @param list<PageTreeItem> $items
     */
    private function depthOf(array $items, string $pageUid): ?int
    {
        foreach ($items as $item) {
            if ($item->result->page->uid === $pageUid) {
                return $item->depth;
            }
        }

        return null;
    }

    /**
     * @param list<PageTreeItem> $items
     */
    private function parentTitleOf(array $items, string $pageUid): ?string
    {
        foreach ($items as $item) {
            if ($item->result->page->uid === $pageUid) {
                return $item->parentTitle;
            }
        }

        return null;
    }

    private function createUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    private function createPage(
        User $owner,
        string $workspaceUid,
        string $title,
        ?string $parentPageUid = null,
    ): Page {
        return app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspaceUid,
            type: PageType::Markdown,
            title: $title,
            description: null,
            content: '# ' . $title,
            parentPageUid: $parentPageUid,
        ));
    }
}
