<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\ChangeWorkspaceMembershipRole;
use App\Application\Identity\ChangeWorkspaceMembershipRoleCommand;
use App\Application\Identity\CreatePersonalWorkspaceForUser;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\PageCatalog\GrantPageAccess;
use App\Application\PageCatalog\GrantPageAccessCommand;
use App\Application\PageCatalog\RevokePageAccess;
use App\Application\PageCatalog\RevokePageAccessCommand;
use App\Application\PageCatalog\UpdatePageAccessMode;
use App\Application\PageCatalog\UpdatePageAccessModeCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Events\PagePresenceAccessRevoked;
use App\Models\Page;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class PagePresenceRevocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_revoking_a_user_grant_kicks_the_grantee_who_loses_view(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $outsider = $this->createUser('Outside User', 'outsider@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Presence Team');
        // The grantee stays outside the page workspace, so the page grant is their
        // only path to this page and revoking it makes them lose view.
        $sharedWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Shared Team');
        $this->addMember($sharedWorkspace->uid, $outsider, WorkspaceRole::Reader);
        $page = $this->createPage($workspace->uid, $admin, 'Granted page');

        app(GrantPageAccess::class)->handle($admin, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $outsider->uid,
            role: WorkspaceRole::Reader,
        ));

        Event::fake([PagePresenceAccessRevoked::class]);

        app(RevokePageAccess::class)->handle($admin, new RevokePageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $outsider->uid,
        ));

        $this->assertKicked($page->uid, $outsider->uid);
        $this->assertNotKicked($page->uid, $admin->uid);
    }

    public function test_revoking_a_redundant_user_grant_does_not_kick_a_member_who_retains_view(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $member = $this->createUser('Member User', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Presence Team');
        $this->addMember($workspace->uid, $member, WorkspaceRole::Reader);
        $page = $this->createPage($workspace->uid, $admin, 'Inherited page');

        app(GrantPageAccess::class)->handle($admin, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $member->uid,
            role: WorkspaceRole::Reader,
        ));

        Event::fake([PagePresenceAccessRevoked::class]);

        app(RevokePageAccess::class)->handle($admin, new RevokePageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $member->uid,
        ));

        Event::assertNotDispatched(PagePresenceAccessRevoked::class);
    }

    public function test_revoking_a_workspace_grant_kicks_grantee_members_who_lose_view(): void
    {
        $sourceAdmin = $this->createUser('Source Admin', 'source-admin@example.test');
        $targetAdmin = $this->createUser('Target Admin', 'target-admin@example.test');
        $member = $this->createUser('Member User', 'member@example.test');
        $memberWithGrant = $this->createUser('Granted Member', 'granted-member@example.test');
        $sourceWorkspace = app(CreateSharedWorkspace::class)->handle($sourceAdmin, 'Source Team');
        $targetWorkspace = app(CreateSharedWorkspace::class)->handle($targetAdmin, 'Target Team');
        $this->addMember($targetWorkspace->uid, $sourceAdmin, WorkspaceRole::Reader);
        $this->addMember($targetWorkspace->uid, $member, WorkspaceRole::Reader);
        $this->addMember($targetWorkspace->uid, $memberWithGrant, WorkspaceRole::Reader);
        $page = $this->createPage($sourceWorkspace->uid, $sourceAdmin, 'Shared page');
        $page->forceFill(['access_mode' => PageAccessMode::Restricted])->save();

        app(GrantPageAccess::class)->handle($sourceAdmin, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::Workspace,
            subjectUid: $targetWorkspace->uid,
            role: WorkspaceRole::Reader,
        ));
        app(GrantPageAccess::class)->handle($sourceAdmin, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $memberWithGrant->uid,
            role: WorkspaceRole::Reader,
        ));

        Event::fake([PagePresenceAccessRevoked::class]);

        app(RevokePageAccess::class)->handle($sourceAdmin, new RevokePageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::Workspace,
            subjectUid: $targetWorkspace->uid,
        ));

        $this->assertKicked($page->uid, $member->uid);
        $this->assertNotKicked($page->uid, $memberWithGrant->uid);
        $this->assertNotKicked($page->uid, $sourceAdmin->uid);
    }

    public function test_restricting_a_page_kicks_members_without_another_access_path(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $owner = $this->createUser('Owner User', 'owner@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $grantedReader = $this->createUser('Granted Reader', 'granted-reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Presence Team');
        $this->addMember($workspace->uid, $owner, WorkspaceRole::Editor);
        $this->addMember($workspace->uid, $reader, WorkspaceRole::Reader);
        $this->addMember($workspace->uid, $grantedReader, WorkspaceRole::Reader);
        $page = $this->createPage($workspace->uid, $owner, 'Soon restricted page');

        app(GrantPageAccess::class)->handle($admin, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $grantedReader->uid,
            role: WorkspaceRole::Reader,
        ));

        Event::fake([PagePresenceAccessRevoked::class]);

        app(UpdatePageAccessMode::class)->handle($admin, new UpdatePageAccessModeCommand(
            pageUid: $page->uid,
            accessMode: PageAccessMode::Restricted,
        ));

        $this->assertKicked($page->uid, $reader->uid);
        $this->assertNotKicked($page->uid, $admin->uid);
        $this->assertNotKicked($page->uid, $owner->uid);
        $this->assertNotKicked($page->uid, $grantedReader->uid);
    }

    public function test_relaxing_a_page_to_inherited_kicks_nobody(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Presence Team');
        $this->addMember($workspace->uid, $reader, WorkspaceRole::Reader);
        $page = $this->createPage($workspace->uid, $admin, 'Restricted page');
        $page->forceFill(['access_mode' => PageAccessMode::Restricted])->save();

        Event::fake([PagePresenceAccessRevoked::class]);

        app(UpdatePageAccessMode::class)->handle($admin, new UpdatePageAccessModeCommand(
            pageUid: $page->uid,
            accessMode: PageAccessMode::Inherited,
        ));

        Event::assertNotDispatched(PagePresenceAccessRevoked::class);
    }

    public function test_admin_demotion_kicks_presence_on_restricted_pages_they_can_no_longer_view(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $demoted = $this->createUser('Demoted Admin', 'demoted@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Presence Team');
        $membership = $this->addMember($workspace->uid, $demoted, WorkspaceRole::Admin);
        $restrictedPage = $this->createPage($workspace->uid, $admin, 'Restricted page');
        $restrictedPage->forceFill(['access_mode' => PageAccessMode::Restricted])->save();
        $inheritedPage = $this->createPage($workspace->uid, $admin, 'Inherited page');

        Event::fake([PagePresenceAccessRevoked::class]);

        app(ChangeWorkspaceMembershipRole::class)->handle($admin, new ChangeWorkspaceMembershipRoleCommand(
            workspaceUid: $workspace->uid,
            membershipUid: $membership->uid,
            role: WorkspaceRole::Editor,
        ));

        $this->assertKicked($restrictedPage->uid, $demoted->uid);
        $this->assertNotKicked($inheritedPage->uid, $demoted->uid);
    }

    public function test_non_admin_demotion_kicks_nobody(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Presence Team');
        $membership = $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);
        $restrictedPage = $this->createPage($workspace->uid, $admin, 'Restricted page');
        $restrictedPage->forceFill(['access_mode' => PageAccessMode::Restricted])->save();

        Event::fake([PagePresenceAccessRevoked::class]);

        app(ChangeWorkspaceMembershipRole::class)->handle($admin, new ChangeWorkspaceMembershipRoleCommand(
            workspaceUid: $workspace->uid,
            membershipUid: $membership->uid,
            role: WorkspaceRole::Reader,
        ));

        Event::assertNotDispatched(PagePresenceAccessRevoked::class);
    }

    private function assertKicked(string $pageUid, string $userUid): void
    {
        Event::assertDispatched(
            PagePresenceAccessRevoked::class,
            static fn (PagePresenceAccessRevoked $event): bool =>
                $event->broadcastWith() === ['page_uid' => $pageUid, 'uid' => $userUid],
        );
    }

    private function assertNotKicked(string $pageUid, string $userUid): void
    {
        Event::assertNotDispatched(
            PagePresenceAccessRevoked::class,
            static fn (PagePresenceAccessRevoked $event): bool =>
                $event->broadcastWith() === ['page_uid' => $pageUid, 'uid' => $userUid],
        );
    }

    private function createUser(string $name, string $email): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);

        app(CreatePersonalWorkspaceForUser::class)->handle($user);

        return $user;
    }

    private function addMember(string $workspaceUid, User $user, WorkspaceRole $role): WorkspaceMembership
    {
        return WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspaceUid,
            'user_uid' => $user->uid,
            'role' => $role,
            'accepted_at' => now(),
        ]);
    }

    private function createPage(string $workspaceUid, User $owner, string $title): Page
    {
        return Page::query()->forceCreate([
            'workspace_uid' => $workspaceUid,
            'owner_user_uid' => $owner->uid,
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'description' => null,
            'access_mode' => PageAccessMode::Inherited,
            'type' => PageType::Markdown,
            'status' => PageStatus::Draft,
        ]);
    }
}
