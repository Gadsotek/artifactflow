<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\AcceptWorkspaceInvitation;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\InviteUserToWorkspace;
use App\Application\Identity\InviteUserToWorkspaceCommand;
use App\Application\Identity\RemoveWorkspaceMember;
use App\Application\Identity\RemoveWorkspaceMemberCommand;
use App\Application\PageCatalog\ArtifactPreviewUrl;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\GrantPageAccess;
use App\Application\PageCatalog\GrantPageAccessCommand;
use App\Application\PageCatalog\PageAccess;
use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Events\PagePresenceAccessRevoked;
use App\Mail\WorkspaceInvitationMail;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\PageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class WorkspaceMembershipRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_admin_can_remove_a_member_with_traceability(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $member = $this->createUser('Member User', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $membership = $this->addMember($workspace->uid, $member, WorkspaceRole::Reader);

        app(RemoveWorkspaceMember::class)->handle($admin, new RemoveWorkspaceMemberCommand(
            workspaceUid: $workspace->uid,
            membershipUid: $membership->uid,
            replacementOwnerUserUid: null,
        ));

        $this->assertDatabaseMissing('workspace_memberships', ['uid' => $membership->uid]);

        $event = DomainEvent::query()
            ->where('event_type', 'workspace.membership.removed')
            ->sole();

        $this->assertSame('workspace_membership', $event->aggregate_type);
        $this->assertSame($membership->uid, $event->aggregate_uid);
        $this->assertSame($workspace->uid, $event->payload['workspace_uid']);
        $this->assertSame($member->uid, $event->payload['member_user_uid']);
        $this->assertSame($admin->uid, $event->payload['removed_by_user_uid']);
        $this->assertSame('reader', $event->payload['previous_role']);
        $this->assertSame(0, $event->payload['reassigned_page_count']);
        $this->assertNull($event->payload['replacement_owner_user_uid']);

        $audit = AuditEntry::query()
            ->where('action', 'workspace.membership.removed')
            ->sole();

        $this->assertSame($event->uid, $audit->event_uid);
        $this->assertSame($admin->uid, $audit->actor_user_uid);
        $this->assertSame($membership->uid, $audit->auditable_uid);
        $this->assertSame($member->uid, $audit->metadata['member_user_uid']);
        $this->assertSame(0, $audit->metadata['reassigned_page_count']);
    }

    public function test_member_removal_revokes_accepted_invitation_so_it_cannot_be_replayed(): void
    {
        Mail::fake();

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $member = $this->createUser('Member User', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: $member->email,
                role: WorkspaceRole::Admin,
            ),
        );
        $membership = app(AcceptWorkspaceInvitation::class)->handle($member, $invitation);
        $membership->forceFill(['role' => WorkspaceRole::Reader])->save();

        app(RemoveWorkspaceMember::class)->handle($admin, new RemoveWorkspaceMemberCommand(
            workspaceUid: $workspace->uid,
            membershipUid: $membership->uid,
            replacementOwnerUserUid: null,
        ));

        $this->assertDatabaseMissing('workspace_memberships', ['uid' => $membership->uid]);
        $this->assertDatabaseHas('workspace_invitations', [
            'uid' => $invitation->uid,
        ]);
        $this->assertNotNull($invitation->refresh()->revoked_at);

        try {
            app(AcceptWorkspaceInvitation::class)->handle($member, $invitation->refresh());
            $this->fail('Expected removed member invitation replay to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Revoked workspace invitations cannot be accepted.', $exception->getMessage());
        }

        $this->assertSame(0, WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $member->uid)
            ->count());
        $removalEvent = DomainEvent::query()
            ->where('event_type', 'workspace.membership.removed')
            ->sole();
        $this->assertSame(1, $removalEvent->payload['revoked_invitation_count']);

        $reactivated = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: $member->email,
                role: WorkspaceRole::Editor,
            ),
        );

        $this->assertSame($invitation->uid, $reactivated->uid);
        $this->assertNull($reactivated->accepted_at);
        $this->assertNull($reactivated->accepted_by_user_uid);
        $this->assertNull($reactivated->revoked_at);
        $this->assertSame(WorkspaceRole::Editor, $reactivated->role);

        $newMembership = app(AcceptWorkspaceInvitation::class)->handle($member, $reactivated);
        $this->assertSame(WorkspaceRole::Editor, $newMembership->role);
        Mail::assertQueued(WorkspaceInvitationMail::class, 2);
    }

    public function test_member_removal_revokes_direct_page_grants_and_stale_elevated_grants_do_not_apply(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $member = $this->createUser('Member User', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $membership = $this->addMember($workspace->uid, $member, WorkspaceRole::Reader);
        $page = $this->createPage($workspace->uid, $admin, 'Restricted page');
        $page->forceFill(['access_mode' => PageAccessMode::Restricted])->save();
        $previousPreviewRevision = $page->preview_access_revision;

        $grant = app(GrantPageAccess::class)->handle($admin, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $member->uid,
            role: WorkspaceRole::Editor,
        ));

        $this->assertTrue(app(PageAccess::class)->canEdit($member, $page->refresh()));
        $previousPreviewRevision = $page->refresh()->preview_access_revision;

        app(RemoveWorkspaceMember::class)->handle($admin, new RemoveWorkspaceMemberCommand(
            workspaceUid: $workspace->uid,
            membershipUid: $membership->uid,
            replacementOwnerUserUid: null,
        ));

        $this->assertDatabaseMissing('page_access_grants', ['uid' => $grant->uid]);
        $this->assertSame($previousPreviewRevision + 1, $page->refresh()->preview_access_revision);
        $this->assertFalse(app(PageAccess::class)->canView($member, $page->refresh()));
        $this->assertFalse(app(PageAccess::class)->canEdit($member, $page->refresh()));

        $removalEvent = DomainEvent::query()
            ->where('event_type', 'workspace.membership.removed')
            ->sole();
        $this->assertSame(1, $removalEvent->payload['revoked_page_access_grant_count']);

        $grantRevocationEvent = DomainEvent::query()
            ->where('event_type', 'page.access_grant.revoked')
            ->sole();
        $this->assertSame($grant->uid, $grantRevocationEvent->payload['page_access_grant_uid']);
        $this->assertSame('workspace_member_removed', $grantRevocationEvent->payload['reason']);

        $grantRevocationAudit = AuditEntry::query()
            ->where('action', 'page.access_grant.revoked')
            ->sole();
        $this->assertArrayNotHasKey('page_access_grant_uid', $grantRevocationAudit->metadata);
        $this->assertArrayNotHasKey('revoked_by_user_uid', $grantRevocationAudit->metadata);

        $removalAudit = AuditEntry::query()
            ->where('action', 'workspace.membership.removed')
            ->sole();
        $this->assertSame(1, $removalAudit->metadata['revoked_page_access_grant_count']);

        $staleGrantedAt = $removalEvent->occurred_at->subSecond();
        PageAccessGrant::query()->forceCreate([
            'page_uid' => $page->uid,
            'subject_type' => PageAccessSubjectType::User,
            'subject_uid' => $member->uid,
            'role' => WorkspaceRole::Admin,
            'granted_by_user_uid' => $admin->uid,
            'created_at' => $staleGrantedAt,
            'updated_at' => $staleGrantedAt,
        ]);

        $this->assertFalse(app(PageAccess::class)->canView($member, $page->refresh()));
        $this->assertFalse(app(PageAccess::class)->canManageAccess($member, $page->refresh()));
        $this->assertFalse(app(PageAccess::class)->canHardDelete($member, $page->refresh()));

        $this->actingAs($member)
            ->get('/pages?workspace_uid=all&q=Restricted')
            ->assertOk()
            ->assertDontSee('Restricted page');
    }

    public function test_member_removal_invalidates_previously_signed_artifact_preview_urls(): void
    {
        Storage::fake('artifacts');
        config(['app.artifact_url' => 'http://localhost']);

        $admin = $this->createUser('Admin User', 'preview-admin@example.test');
        $member = $this->createUser('Member User', 'preview-member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Preview Team');
        $membership = $this->addMember($workspace->uid, $member, WorkspaceRole::Reader);
        $page = app(CreatePage::class)->handle($admin, new CreatePageCommand(
            workspaceUid: $workspace->uid,
            type: PageType::HtmlArtifact,
            title: 'Preview Removal',
            description: null,
            content: '<!doctype html><html><body><h1>Before Removal</h1></body></html>',
        ));
        app(GrantPageAccess::class)->handle($admin, new GrantPageAccessCommand(
            pageUid: $page->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $member->uid,
            role: WorkspaceRole::Reader,
        ));
        $version = PageVersion::query()->where('page_uid', $page->uid)->sole();
        $url = app(ArtifactPreviewUrl::class)->temporaryUrl($page->refresh(), $version);

        app(RemoveWorkspaceMember::class)->handle($admin, new RemoveWorkspaceMemberCommand(
            workspaceUid: $workspace->uid,
            membershipUid: $membership->uid,
            replacementOwnerUserUid: null,
        ));

        config(['app.runtime_role' => 'artifact-host']);

        $this->get($url)->assertNotFound();
    }

    public function test_member_removal_invalidates_preview_urls_for_pages_shared_to_their_workspace(): void
    {
        $sourceAdmin = $this->createUser('Source Admin', 'source-admin@example.test');
        $targetAdmin = $this->createUser('Target Admin', 'target-admin@example.test');
        $targetMember = $this->createUser('Target Member', 'target-member@example.test');
        $sourceWorkspace = app(CreateSharedWorkspace::class)->handle($sourceAdmin, 'Source Team');
        $targetWorkspace = app(CreateSharedWorkspace::class)->handle($targetAdmin, 'Target Team');
        $this->addMember($targetWorkspace->uid, $sourceAdmin, WorkspaceRole::Reader);
        $targetMembership = $this->addMember($targetWorkspace->uid, $targetMember, WorkspaceRole::Reader);
        $sharedPage = $this->createPage($sourceWorkspace->uid, $sourceAdmin, 'Workspace shared page');
        $sharedPage->forceFill(['access_mode' => PageAccessMode::Restricted])->save();

        app(GrantPageAccess::class)->handle($sourceAdmin, new GrantPageAccessCommand(
            pageUid: $sharedPage->uid,
            subjectType: PageAccessSubjectType::Workspace,
            subjectUid: $targetWorkspace->uid,
            role: WorkspaceRole::Reader,
        ));
        $previousPreviewRevision = $sharedPage->refresh()->preview_access_revision;

        app(RemoveWorkspaceMember::class)->handle($targetAdmin, new RemoveWorkspaceMemberCommand(
            workspaceUid: $targetWorkspace->uid,
            membershipUid: $targetMembership->uid,
            replacementOwnerUserUid: null,
        ));

        $this->assertSame($previousPreviewRevision + 1, $sharedPage->refresh()->preview_access_revision);
        $this->assertFalse(app(PageAccess::class)->canView($targetMember, $sharedPage));
    }

    public function test_member_removal_broadcasts_presence_revocation_for_workspace_pages(): void
    {
        Event::fake([PagePresenceAccessRevoked::class]);

        $admin = $this->createUser('Admin User', 'presence-admin@example.test');
        $member = $this->createUser('Member User', 'presence-member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Presence Revocation Team');
        $membership = $this->addMember($workspace->uid, $member, WorkspaceRole::Reader);
        $firstPage = $this->createPage($workspace->uid, $admin, 'First presence page');
        $secondPage = $this->createPage($workspace->uid, $admin, 'Second presence page');

        app(RemoveWorkspaceMember::class)->handle($admin, new RemoveWorkspaceMemberCommand(
            workspaceUid: $workspace->uid,
            membershipUid: $membership->uid,
            replacementOwnerUserUid: null,
        ));

        $broadcastedPageUids = [];
        Event::assertDispatched(PagePresenceAccessRevoked::class, function (PagePresenceAccessRevoked $event) use (&$broadcastedPageUids, $member): bool {
            $payload = $event->broadcastWith();

            if ($payload['uid'] !== $member->uid || $event->broadcastAs() !== 'page.access.revoked') {
                return false;
            }

            $broadcastedPageUids[] = $payload['page_uid'];

            return true;
        });

        $this->assertEqualsCanonicalizing([$firstPage->uid, $secondPage->uid], $broadcastedPageUids);
        $event = DomainEvent::query()
            ->where('event_type', 'workspace.membership.removed')
            ->sole();
        $this->assertSame(2, $event->payload['presence_revocation_page_count']);
    }

    public function test_member_removal_kicks_presence_on_pages_granted_to_their_workspace(): void
    {
        $sourceAdmin = $this->createUser('Source Admin', 'source-admin@example.test');
        $targetAdmin = $this->createUser('Target Admin', 'target-admin@example.test');
        $member = $this->createUser('Member User', 'member@example.test');
        $sourceWorkspace = app(CreateSharedWorkspace::class)->handle($sourceAdmin, 'Source Team');
        $targetWorkspace = app(CreateSharedWorkspace::class)->handle($targetAdmin, 'Target Team');
        $this->addMember($targetWorkspace->uid, $sourceAdmin, WorkspaceRole::Reader);
        $membership = $this->addMember($targetWorkspace->uid, $member, WorkspaceRole::Reader);
        $sharedPage = $this->createPage($sourceWorkspace->uid, $sourceAdmin, 'Workspace shared page');
        $sharedPage->forceFill(['access_mode' => PageAccessMode::Restricted])->save();
        $directlyGrantedPage = $this->createPage($sourceWorkspace->uid, $sourceAdmin, 'Directly granted page');
        $directlyGrantedPage->forceFill(['access_mode' => PageAccessMode::Restricted])->save();

        app(GrantPageAccess::class)->handle($sourceAdmin, new GrantPageAccessCommand(
            pageUid: $sharedPage->uid,
            subjectType: PageAccessSubjectType::Workspace,
            subjectUid: $targetWorkspace->uid,
            role: WorkspaceRole::Reader,
        ));
        app(GrantPageAccess::class)->handle($sourceAdmin, new GrantPageAccessCommand(
            pageUid: $directlyGrantedPage->uid,
            subjectType: PageAccessSubjectType::User,
            subjectUid: $member->uid,
            role: WorkspaceRole::Reader,
        ));

        Event::fake([PagePresenceAccessRevoked::class]);

        app(RemoveWorkspaceMember::class)->handle($targetAdmin, new RemoveWorkspaceMemberCommand(
            workspaceUid: $targetWorkspace->uid,
            membershipUid: $membership->uid,
            replacementOwnerUserUid: null,
        ));

        Event::assertDispatched(
            PagePresenceAccessRevoked::class,
            static fn (PagePresenceAccessRevoked $event): bool =>
                $event->broadcastWith() === ['page_uid' => $sharedPage->uid, 'uid' => $member->uid],
        );
        Event::assertNotDispatched(
            PagePresenceAccessRevoked::class,
            static fn (PagePresenceAccessRevoked $event): bool =>
                $event->broadcastWith() === ['page_uid' => $directlyGrantedPage->uid, 'uid' => $member->uid],
        );
    }

    public function test_owned_pages_require_an_eligible_replacement_member(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $member = $this->createUser('Member User', 'member@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $outsider = $this->createUser('Outside User', 'outside@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $membership = $this->addMember($workspace->uid, $member, WorkspaceRole::Editor);
        $this->addMember($workspace->uid, $reader, WorkspaceRole::Reader);
        $page = $this->createPage($workspace->uid, $member, 'Owned page');
        $handler = app(RemoveWorkspaceMember::class);

        foreach ([
            [null, 'A replacement owner is required for pages owned by this member.'],
            [$reader->uid, 'Replacement page owner must be a workspace editor or admin.'],
            [$outsider->uid, 'Replacement page owner must belong to this workspace.'],
            [$member->uid, 'Replacement page owner must be a different workspace member.'],
        ] as [$replacementOwnerUserUid, $expectedMessage]) {
            try {
                $handler->handle($admin, new RemoveWorkspaceMemberCommand(
                    workspaceUid: $workspace->uid,
                    membershipUid: $membership->uid,
                    replacementOwnerUserUid: $replacementOwnerUserUid,
                ));
                $this->fail('Expected unsafe workspace membership removal to be rejected.');
            } catch (DomainRuleViolation $exception) {
                $this->assertSame($expectedMessage, $exception->getMessage());
            }
        }

        $this->assertDatabaseHas('workspace_memberships', ['uid' => $membership->uid]);
        $this->assertSame($member->uid, $page->refresh()->owner_user_uid);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'workspace.membership.removed')->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'page.ownership.transferred')->count());
    }

    public function test_admin_can_reassign_owned_pages_while_removing_a_member(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $member = $this->createUser('Member User', 'member@example.test');
        $replacement = $this->createUser('Replacement User', 'replacement@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $membership = $this->addMember($workspace->uid, $member, WorkspaceRole::Editor);
        $this->addMember($workspace->uid, $replacement, WorkspaceRole::Editor);
        $firstPage = $this->createPage($workspace->uid, $member, 'First page');
        $secondPage = $this->createPage($workspace->uid, $member, 'Second page');

        app(RemoveWorkspaceMember::class)->handle($admin, new RemoveWorkspaceMemberCommand(
            workspaceUid: $workspace->uid,
            membershipUid: $membership->uid,
            replacementOwnerUserUid: $replacement->uid,
        ));

        $this->assertDatabaseMissing('workspace_memberships', ['uid' => $membership->uid]);
        $this->assertSame($replacement->uid, $firstPage->refresh()->owner_user_uid);
        $this->assertSame($replacement->uid, $secondPage->refresh()->owner_user_uid);

        $ownershipEvents = DomainEvent::query()
            ->where('event_type', 'page.ownership.transferred')
            ->orderBy('aggregate_uid')
            ->get();

        $this->assertCount(2, $ownershipEvents);
        $this->assertSame(
            [$firstPage->uid, $secondPage->uid],
            $ownershipEvents->pluck('aggregate_uid')->sort()->values()->all(),
        );

        foreach ($ownershipEvents as $event) {
            $this->assertSame($workspace->uid, $event->payload['workspace_uid']);
            $this->assertSame($member->uid, $event->payload['previous_owner_user_uid']);
            $this->assertSame($replacement->uid, $event->payload['new_owner_user_uid']);
            $this->assertSame($admin->uid, $event->payload['transferred_by_user_uid']);
        }

        $this->assertSame(2, AuditEntry::query()->where('action', 'page.ownership.transferred')->count());

        $removalEvent = DomainEvent::query()
            ->where('event_type', 'workspace.membership.removed')
            ->sole();

        $this->assertSame(2, $removalEvent->payload['reassigned_page_count']);
        $this->assertSame($replacement->uid, $removalEvent->payload['replacement_owner_user_uid']);
    }

    public function test_member_removal_reassignment_invalidates_open_metadata_forms(): void
    {
        $admin = $this->createUser('Admin User', 'revision-admin@example.test');
        $member = $this->createUser('Member User', 'revision-member@example.test');
        $replacement = $this->createUser('Replacement User', 'revision-replacement@example.test');
        $staleOwnerChoice = $this->createUser('Stale Owner Choice', 'revision-stale-owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Revision Team');
        $membership = $this->addMember($workspace->uid, $member, WorkspaceRole::Editor);
        $this->addMember($workspace->uid, $replacement, WorkspaceRole::Editor);
        $this->addMember($workspace->uid, $staleOwnerChoice, WorkspaceRole::Editor);
        $page = $this->createPage($workspace->uid, $member, 'Revision protected page')->refresh();
        $openedMetadataRevision = $page->metadata_revision;

        app(RemoveWorkspaceMember::class)->handle($admin, new RemoveWorkspaceMemberCommand(
            workspaceUid: $workspace->uid,
            membershipUid: $membership->uid,
            replacementOwnerUserUid: $replacement->uid,
        ));

        $page->refresh();
        $this->assertSame($openedMetadataRevision + 1, $page->metadata_revision);

        $this->actingAs($admin)
            ->put("/pages/{$page->uid}/metadata", [
                'metadata_revision' => $openedMetadataRevision,
                'title' => $page->title,
                'owner_user_uid' => $staleOwnerChoice->uid,
            ])
            ->assertStatus(409);

        $this->assertSame($replacement->uid, $page->refresh()->owner_user_uid);
    }

    public function test_non_admin_outsider_personal_workspace_and_last_admin_removal_are_rejected(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $member = $this->createUser('Member User', 'member@example.test');
        $outsider = $this->createUser('Outside User', 'outside@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);
        $membership = $this->addMember($workspace->uid, $member, WorkspaceRole::Reader);
        $command = new RemoveWorkspaceMemberCommand(
            workspaceUid: $workspace->uid,
            membershipUid: $membership->uid,
            replacementOwnerUserUid: null,
        );

        foreach ([$editor, $outsider] as $actor) {
            try {
                app(RemoveWorkspaceMember::class)->handle($actor, $command);
                $this->fail('Expected workspace membership removal to be forbidden.');
            } catch (AuthorizationException $exception) {
                $this->assertSame('Only workspace admins can remove members.', $exception->getMessage());
            }
        }

        $personalWorkspace = Workspace::query()
            ->where('personal_owner_uid', $admin->uid)
            ->sole();
        $personalMembership = WorkspaceMembership::query()
            ->where('workspace_uid', $personalWorkspace->uid)
            ->where('user_uid', $admin->uid)
            ->sole();

        try {
            app(RemoveWorkspaceMember::class)->handle($admin, new RemoveWorkspaceMemberCommand(
                workspaceUid: $personalWorkspace->uid,
                membershipUid: $personalMembership->uid,
                replacementOwnerUserUid: null,
            ));
            $this->fail('Expected personal workspace membership removal to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Personal workspace memberships cannot be removed.', $exception->getMessage());
        }

        $adminMembership = WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $admin->uid)
            ->sole();

        try {
            app(RemoveWorkspaceMember::class)->handle($admin, new RemoveWorkspaceMemberCommand(
                workspaceUid: $workspace->uid,
                membershipUid: $adminMembership->uid,
                replacementOwnerUserUid: $editor->uid,
            ));
            $this->fail('Expected last workspace admin removal to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('A shared workspace must retain at least one admin.', $exception->getMessage());
        }

        $this->assertDatabaseHas('workspace_memberships', ['uid' => $membership->uid]);
        $this->assertDatabaseHas('workspace_memberships', ['uid' => $adminMembership->uid]);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'workspace.membership.removed')->count());
    }

    public function test_workspace_admin_can_remove_a_member_from_the_dashboard_with_page_reassignment(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $member = $this->createUser('Member User', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $membership = $this->addMember($workspace->uid, $member, WorkspaceRole::Editor);
        $page = $this->createPage($workspace->uid, $member, 'Owned page');

        $this->actingAs($admin)
            ->withSession(['current_workspace_uid' => $workspace->uid])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee("workspaces/{$workspace->uid}/memberships/{$membership->uid}", false)
            ->assertSee('Remove member')
            ->assertSee('Reassign owned pages to');

        $this->actingAs($admin)
            ->delete("/workspaces/{$workspace->uid}/memberships/{$membership->uid}", [
                'replacement_owner_user_uid' => $admin->uid,
            ])
            ->assertRedirect('/dashboard')
            ->assertSessionHas('status', 'Workspace member removed.')
            ->assertSessionHas('current_workspace_uid', $workspace->uid);

        $this->assertDatabaseMissing('workspace_memberships', ['uid' => $membership->uid]);
        $this->assertSame($admin->uid, $page->refresh()->owner_user_uid);
    }

    public function test_membership_removal_http_boundary_enforces_auth_validation_authorization_and_scope(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $reader = $this->createUser('Reader User', 'reader@example.test');
        $member = $this->createUser('Member User', 'member@example.test');
        $otherAdmin = $this->createUser('Other Admin', 'other-admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $this->addMember($workspace->uid, $reader, WorkspaceRole::Reader);
        $membership = $this->addMember($workspace->uid, $member, WorkspaceRole::Reader);
        $otherWorkspace = app(CreateSharedWorkspace::class)->handle($otherAdmin, 'Other Team');
        $foreignMembership = $this->addMember($otherWorkspace->uid, $member, WorkspaceRole::Reader);

        $this->delete("/workspaces/{$workspace->uid}/memberships/{$membership->uid}")
            ->assertRedirect('/login');

        $this->actingAs($reader)
            ->withSession(['current_workspace_uid' => $workspace->uid])
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Remove member');

        $this->actingAs($reader)
            ->delete("/workspaces/{$workspace->uid}/memberships/{$membership->uid}")
            ->assertForbidden();

        $this->actingAs($admin)
            ->delete("/workspaces/{$workspace->uid}/memberships/{$membership->uid}", [
                'replacement_owner_user_uid' => 'not-a-ulid',
            ])
            ->assertSessionHasErrors('replacement_owner_user_uid');

        $this->actingAs($admin)
            ->delete("/workspaces/{$workspace->uid}/memberships/{$foreignMembership->uid}")
            ->assertNotFound();

        $this->assertDatabaseHas('workspace_memberships', ['uid' => $membership->uid]);
        $this->assertDatabaseHas('workspace_memberships', ['uid' => $foreignMembership->uid]);
    }

    private function createUser(string $name, string $email): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);

        app(\App\Application\Identity\CreatePersonalWorkspaceForUser::class)->handle($user);

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
