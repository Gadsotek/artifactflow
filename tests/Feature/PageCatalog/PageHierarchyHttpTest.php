<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\GrantPageAccess;
use App\Application\PageCatalog\GrantPageAccessCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class PageHierarchyHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_detail_shows_navigable_parent_and_child_pages(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $parent = $this->createPage($owner, $workspace->uid, 'Parent Page');
        $page = $this->createPage($owner, $workspace->uid, 'Current Page', $parent->uid);
        $child = $this->createPage($owner, $workspace->uid, 'Child Page', $page->uid);

        $this->actingAs($owner)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Page hierarchy')
            ->assertSee('aria-label="Parent page: Parent Page"', false)
            ->assertSee('href="' . route('pages.show', $parent) . '"', false)
            ->assertSee('aria-label="Child page: Child Page"', false)
            ->assertSee('href="' . route('pages.show', $child) . '"', false);
    }

    public function test_page_hierarchy_hides_parent_and_children_the_viewer_cannot_access(): void
    {
        Storage::fake('artifacts');

        $owner = $this->createUser('Owner User', 'owner@example.test');
        $viewer = $this->createUser('Outside Viewer', 'viewer@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Security Team');
        // The viewer stays outside the page workspace, so it reaches only pages
        // explicitly granted to it, never the whole hierarchy by inheritance.
        $sharedWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Shared Team');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $sharedWorkspace->uid,
            'user_uid' => $viewer->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        $parent = $this->createPage($owner, $workspace->uid, 'Restricted Parent');
        $page = $this->createPage($owner, $workspace->uid, 'Granted Current Page', $parent->uid);
        $visibleChild = $this->createPage($owner, $workspace->uid, 'Granted Child Page', $page->uid);
        $hiddenChild = $this->createPage($owner, $workspace->uid, 'Hidden Child Page', $page->uid);

        foreach ([$parent, $page, $visibleChild, $hiddenChild] as $restrictedPage) {
            $restrictedPage->forceFill(['access_mode' => PageAccessMode::Restricted])->save();
        }

        foreach ([$page, $visibleChild] as $grantedPage) {
            app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
                pageUid: $grantedPage->uid,
                subjectType: PageAccessSubjectType::User,
                subjectUid: $viewer->uid,
                role: WorkspaceRole::Reader,
            ));
        }

        $this->actingAs($viewer)
            ->get("/pages/{$page->uid}")
            ->assertOk()
            ->assertSee('Page hierarchy')
            ->assertSee('Granted Child Page')
            ->assertSee(route('pages.show', $visibleChild), false)
            ->assertDontSee('Restricted Parent')
            ->assertDontSee($parent->uid)
            ->assertDontSee('Hidden Child Page')
            ->assertDontSee($hiddenChild->uid);
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
