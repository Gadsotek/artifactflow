<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\GrantPageAccess;
use App\Application\PageCatalog\GrantPageAccessCommand;
use App\Application\PageCatalog\PageAccess;
use App\Application\PageCatalog\UpdatePageAccessMode;
use App\Application\PageCatalog\UpdatePageAccessModeCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Direct internal-user grants are independent from unrelated workspace edges.
 */
final class PageGrantInternalCoworkerReadEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reader_grant_survives_unrelated_shared_workspace_removal(): void
    {
        Storage::fake('artifacts');

        [$owner, $pageWorkspace] = $this->ownerWithWorkspace('owner@example.test', 'Page Team');
        $collaborator = app(CreateUser::class)->handle('Collaborator', 'collab@example.test', 'correct horse battery staple');
        // The collaborator reaches the owner only through a *separate* shared
        // workspace, never the page's own workspace.
        $sharedWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Shared Team');
        $membership = WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $sharedWorkspace->uid,
            'user_uid' => $collaborator->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);

        $page = $this->restrictedPage($owner, $pageWorkspace);

        app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $collaborator->uid,
            role: WorkspaceRole::Reader,
        ));

        $access = app(PageAccess::class);
        $this->assertTrue($access->canView($collaborator, $page->refresh()));

        // The explicit grant belongs to another page/workspace permission boundary,
        // so removing an unrelated shared membership must not revoke it.
        $membership->delete();
        $this->assertSame(1, PageAccessGrant::query()->where('page_uid', $page->uid)->count());

        $access->flushCache();
        $this->assertTrue($access->canView($collaborator, $page->refresh()));
    }

    public function test_a_direct_grant_to_an_internal_coworker_needs_no_shared_workspace(): void
    {
        Storage::fake('artifacts');

        [$owner, $pageWorkspace] = $this->ownerWithWorkspace('legacy-owner@example.test', 'Legacy Team');
        $coworker = app(CreateUser::class)->handle('Coworker', 'coworker@example.test', 'correct horse battery staple');
        $page = $this->restrictedPage($owner, $pageWorkspace);

        PageAccessGrant::query()->forceCreate([
            'page_uid' => $page->uid,
            'subject_type' => PageAccessSubjectType::User,
            'subject_uid' => $coworker->uid,
            'role' => WorkspaceRole::Reader,
            'granted_by_user_uid' => $owner->uid,
        ]);

        $access = app(PageAccess::class);
        $this->assertTrue($access->canView($coworker, $page->refresh()));
    }

    public function test_a_grant_to_a_member_of_the_page_workspace_is_still_honored(): void
    {
        Storage::fake('artifacts');

        [$owner, $pageWorkspace] = $this->ownerWithWorkspace('member-owner@example.test', 'Member Team');
        $member = app(CreateUser::class)->handle('Member', 'member@example.test', 'correct horse battery staple');
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $pageWorkspace->uid,
            'user_uid' => $member->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        $page = $this->restrictedPage($owner, $pageWorkspace);

        app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $member->uid,
            role: WorkspaceRole::Reader,
        ));

        // Workspace members remain valid explicit-grant subjects too.
        $this->assertTrue(app(PageAccess::class)->canView($member, $page->refresh()));
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function ownerWithWorkspace(string $email, string $name): array
    {
        $owner = app(CreateUser::class)->handle('Owner', $email, 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, $name);

        return [$owner, $workspace];
    }

    private function restrictedPage(User $owner, Workspace $workspace): Page
    {
        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Restricted Grant Page',
            description: null,
            content: '# Restricted Grant Page',
        ));

        app(UpdatePageAccessMode::class)->handle($owner, new UpdatePageAccessModeCommand(
            pageUid: $page->uid,
            accessMode: PageAccessMode::Restricted,
        ));

        return $page->refresh();
    }
}
