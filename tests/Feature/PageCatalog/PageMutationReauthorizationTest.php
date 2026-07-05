<?php

declare(strict_types=1);

namespace Tests\Feature\PageCatalog;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\CreateUser;
use App\Application\PageCatalog\ArchivePage;
use App\Application\PageCatalog\ArchivePageCommand;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\GrantPageAccess;
use App\Application\PageCatalog\GrantPageAccessCommand;
use App\Application\PageCatalog\MarkPageApproved;
use App\Application\PageCatalog\MarkPageApprovedCommand;
use App\Application\PageCatalog\MovePageToWorkspace;
use App\Application\PageCatalog\MovePageToWorkspaceCommand;
use App\Application\PageCatalog\PageAccess;
use App\Application\PageCatalog\RestorePageVersion;
use App\Application\PageCatalog\RestorePageVersionCommand;
use App\Application\PageCatalog\RevokePageAccess;
use App\Application\PageCatalog\RevokePageAccessCommand;
use App\Application\PageCatalog\UpdatePageAccessMode;
use App\Application\PageCatalog\UpdatePageAccessModeCommand;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Application\PageCatalog\UpdatePageMetadata;
use App\Application\PageCatalog\UpdatePageMetadataCommand;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\PageVersion;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Every page mutation authorizes again under the page row lock through
 * PageAccess::lockAndReauthorize, which discards the request-scoped authority cache before
 * re-checking. These tests pin that second check across the authority classes the first
 * audit pass missed -- metadata, restore, lifecycle (edit- and admin-gated), and grant
 * revocation. Each primes the scoped cache the way an earlier authorization in the same
 * request would, commits a role change, then asserts the mutation is refused against the
 * fresh state rather than the stale cached decision.
 */
final class PageMutationReauthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata_update_is_refused_when_edit_access_was_revoked_after_the_cached_check(): void
    {
        Storage::fake('artifacts');
        [$owner, $editor, $workspace, $page] = $this->workspaceWithPage();

        $this->assertTrue(app(PageAccess::class)->canEdit($editor, $page));
        $this->demote($workspace->uid, $editor, WorkspaceRole::Reader);

        $this->assertRefused('You cannot edit this page.', function () use ($editor, $page, $owner): void {
            app(UpdatePageMetadata::class)->handle($editor, new UpdatePageMetadataCommand(
                pageUid: $page->uid,
                title: 'Renamed After Revocation',
                description: null,
                categoryUid: null,
                parentPageUid: null,
                ownerUserUid: $owner->uid,
                tagNames: [],
            ));
        });

        $this->assertSame('Shared Runbook', $page->refresh()->title);
    }

    public function test_version_restore_is_refused_when_edit_access_was_revoked_after_the_cached_check(): void
    {
        Storage::fake('artifacts');
        [$owner, $editor, $workspace, $page] = $this->workspaceWithPage();

        $firstVersion = PageVersion::query()->where('page_uid', $page->uid)->sole();
        app(UpdatePageContent::class)->handle($owner, new UpdatePageContentCommand(
            pageUid: $page->uid,
            content: '# Second revision',
            baseVersionUid: $firstVersion->uid,
        ));
        $currentVersionUid = (string) $page->refresh()->current_version_uid;

        $this->assertTrue(app(PageAccess::class)->canEdit($editor, $page));
        $this->demote($workspace->uid, $editor, WorkspaceRole::Reader);

        $this->assertRefused('You cannot edit this page.', function () use ($editor, $page, $firstVersion, $currentVersionUid): void {
            app(RestorePageVersion::class)->handle($editor, new RestorePageVersionCommand(
                pageUid: $page->uid,
                versionUid: $firstVersion->uid,
                expectedCurrentVersionUid: $currentVersionUid,
            ));
        });

        // No restore version was appended: the current version is unchanged.
        $this->assertSame($currentVersionUid, (string) $page->refresh()->current_version_uid);
    }

    public function test_approve_is_refused_when_edit_access_was_revoked_after_the_cached_check(): void
    {
        Storage::fake('artifacts');
        [, $editor, $workspace, $page] = $this->workspaceWithPage();
        $this->assertSame(PageStatus::Draft, $page->status);

        $this->assertTrue(app(PageAccess::class)->canEdit($editor, $page));
        $this->demote($workspace->uid, $editor, WorkspaceRole::Reader);

        $this->assertRefused('You cannot edit this page.', function () use ($editor, $page): void {
            app(MarkPageApproved::class)->handle($editor, new MarkPageApprovedCommand($page->uid));
        });

        $this->assertSame(PageStatus::Draft, $page->refresh()->status);
    }

    public function test_archive_is_refused_when_admin_access_was_revoked_after_the_cached_check(): void
    {
        Storage::fake('artifacts');
        [, , $workspace, $page] = $this->workspaceWithPage();
        $secondAdmin = app(CreateUser::class)->handle('Second Admin', 'reauth-archiver@example.test', 'correct horse battery staple');
        $this->addMember($workspace->uid, $secondAdmin, WorkspaceRole::Admin);

        $this->assertTrue(app(PageAccess::class)->canArchive($secondAdmin, $page));
        $this->demote($workspace->uid, $secondAdmin, WorkspaceRole::Reader);

        $this->assertRefused('You cannot archive this page.', function () use ($secondAdmin, $page): void {
            app(ArchivePage::class)->handle($secondAdmin, new ArchivePageCommand($page->uid, confirmed: true));
        });

        $this->assertSame(PageStatus::Draft, $page->refresh()->status);
    }

    public function test_access_revocation_is_refused_when_manage_access_was_lost_after_the_cached_check(): void
    {
        Storage::fake('artifacts');
        [$owner, , $workspace, $page] = $this->workspaceWithPage();
        $secondAdmin = app(CreateUser::class)->handle('Second Admin', 'reauth-revoker@example.test', 'correct horse battery staple');
        $this->addMember($workspace->uid, $secondAdmin, WorkspaceRole::Admin);
        $grantee = app(CreateUser::class)->handle('Grantee', 'reauth-grantee@example.test', 'correct horse battery staple');
        // The grantee belongs to the page workspace and can receive elevated roles.
        $this->addMember($workspace->uid, $grantee, WorkspaceRole::Reader);

        // The owner grants the third user direct reader access, which the second admin
        // would normally be able to revoke.
        app(GrantPageAccess::class)->handle($owner, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $grantee->uid,
            role: WorkspaceRole::Reader,
        ));

        $this->assertTrue(app(PageAccess::class)->canManageAccess($secondAdmin, $page));
        $this->demote($workspace->uid, $secondAdmin, WorkspaceRole::Reader);

        $this->assertRefused('You cannot manage access to this page.', function () use ($secondAdmin, $page, $grantee): void {
            app(RevokePageAccess::class)->handle($secondAdmin, new RevokePageAccessCommand(
                pageUid: $page->uid,
                subjectType: PageAccessSubjectType::User,
                subjectUid: $grantee->uid,
            ));
        });

        // The grant survives the refused revocation.
        $this->assertSame(1, PageAccessGrant::query()
            ->where('page_uid', $page->uid)
            ->where('subject_uid', $grantee->uid)
            ->count());
    }

    public function test_grant_is_refused_when_manage_access_was_lost_after_the_cached_check(): void
    {
        Storage::fake('artifacts');
        [, , $workspace, $page] = $this->workspaceWithPage();
        $secondAdmin = app(CreateUser::class)->handle('Second Admin', 'reauth-granter@example.test', 'correct horse battery staple');
        $this->addMember($workspace->uid, $secondAdmin, WorkspaceRole::Admin);
        $grantee = app(CreateUser::class)->handle('Grantee', 'reauth-grant-target@example.test', 'correct horse battery staple');

        $this->assertTrue(app(PageAccess::class)->canManageAccess($secondAdmin, $page));
        $this->demote($workspace->uid, $secondAdmin, WorkspaceRole::Reader);

        $this->assertRefused('You cannot grant access to this page.', function () use ($secondAdmin, $page, $grantee): void {
            app(GrantPageAccess::class)->handle($secondAdmin, new GrantPageAccessCommand(
                pageUid: $page->uid,
                subjectType: PageAccessSubjectType::User,
                subjectUid: $grantee->uid,
                role: WorkspaceRole::Reader,
            ));
        });

        // No grant was written against the stale cached admin decision.
        $this->assertSame(0, PageAccessGrant::query()
            ->where('page_uid', $page->uid)
            ->where('subject_uid', $grantee->uid)
            ->count());
    }

    public function test_access_mode_change_is_refused_when_admin_access_was_lost_after_the_cached_check(): void
    {
        Storage::fake('artifacts');
        [, , $workspace, $page] = $this->workspaceWithPage();
        $secondAdmin = app(CreateUser::class)->handle('Second Admin', 'reauth-mode@example.test', 'correct horse battery staple');
        $this->addMember($workspace->uid, $secondAdmin, WorkspaceRole::Admin);
        $this->assertSame(PageAccessMode::Inherited, $page->access_mode);

        $this->assertTrue(app(PageAccess::class)->canChangeAccessMode($secondAdmin, $page));
        $this->demote($workspace->uid, $secondAdmin, WorkspaceRole::Reader);

        $this->assertRefused('You cannot change access mode for this page.', function () use ($secondAdmin, $page): void {
            app(UpdatePageAccessMode::class)->handle($secondAdmin, new UpdatePageAccessModeCommand(
                pageUid: $page->uid,
                accessMode: PageAccessMode::Restricted,
            ));
        });

        $this->assertSame(PageAccessMode::Inherited, $page->refresh()->access_mode);
    }

    public function test_workspace_move_is_refused_when_hard_delete_authority_was_lost_after_the_cached_check(): void
    {
        Storage::fake('artifacts');
        [$owner, , $workspace, $page] = $this->workspaceWithPage();
        // The owner is Admin of both workspaces, so the move is fully valid until the source
        // authority is revoked -- isolating the reauthorization as the only thing refusing it.
        $targetWorkspace = app(CreateSharedWorkspace::class)->handle($owner, 'Destination Team');

        $this->assertTrue(app(PageAccess::class)->canHardDelete($owner, $page));
        $this->demote($workspace->uid, $owner, WorkspaceRole::Reader);

        $this->assertRefused('You cannot move this page out of its current workspace.', function () use ($owner, $page, $targetWorkspace): void {
            app(MovePageToWorkspace::class)->handle($owner, new MovePageToWorkspaceCommand(
                pageUid: $page->uid,
                targetWorkspaceUid: $targetWorkspace->uid,
                targetOwnerUserUid: $owner->uid,
                confirmed: true,
            ));
        });

        // The page stays in its source workspace: the move never committed.
        $this->assertSame($workspace->uid, $page->refresh()->workspace_uid);
    }

    /**
     * @return array{0: User, 1: User, 2: \App\Models\Workspace, 3: Page}
     */
    private function workspaceWithPage(): array
    {
        $owner = app(CreateUser::class)->handle('Owner', 'reauth-owner@example.test', 'correct horse battery staple');
        $editor = app(CreateUser::class)->handle('Editor', 'reauth-editor@example.test', 'correct horse battery staple');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'Platform Team');
        $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);

        $page = app(CreatePage::class)->handle($owner, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::Markdown,
            title: 'Shared Runbook',
            description: null,
            content: '# Original',
        ));

        return [$owner, $editor, $workspace, $page];
    }

    private function assertRefused(string $expectedMessage, callable $mutation): void
    {
        $threw = false;

        try {
            $mutation();
        } catch (AuthorizationException $exception) {
            $threw = true;
            $this->assertSame($expectedMessage, $exception->getMessage());
        }

        $this->assertTrue($threw, 'The mutation must be refused under the page lock against fresh authority.');
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

    private function demote(string $workspaceUid, User $user, WorkspaceRole $role): void
    {
        WorkspaceMembership::query()
            ->where('workspace_uid', $workspaceUid)
            ->where('user_uid', $user->uid)
            ->update(['role' => $role->value]);
    }
}
