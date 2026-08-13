<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\AddWorkspaceCollaborator;
use App\Application\Identity\AddWorkspaceCollaboratorCommand;
use App\Application\Identity\ChangeWorkspaceMembershipRole;
use App\Application\Identity\ChangeWorkspaceMembershipRoleCommand;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\ExcludeInheritedWorkspaceMember;
use App\Application\Identity\ExcludeInheritedWorkspaceMemberCommand;
use App\Application\Identity\RemoveWorkspaceMember;
use App\Application\Identity\RemoveWorkspaceMemberCommand;
use App\Application\Identity\ReparentWorkspace;
use App\Application\Identity\ReparentWorkspaceCommand;
use App\Application\PageCatalog\PageAccess;
use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessMode;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\Page;
use App\Models\PageAccessGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class NestedWorkspaceMembershipMutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_removing_a_parent_member_revokes_lost_descendant_reach_and_previews(): void
    {
        $admin = $this->createUser('Root Admin', 'nested-remove-admin@example.test');
        $member = $this->createUser('Root Member', 'nested-remove-member@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($admin, 'Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Child');
        app(ReparentWorkspace::class)->handle($admin, new ReparentWorkspaceCommand($child->uid, $root->uid, true));
        $membership = $this->addMember($root, $member, WorkspaceRole::Editor);
        $page = Page::factory()->create([
            'workspace_uid' => $child->uid,
            'owner_user_uid' => $admin->uid,
            'access_mode' => PageAccessMode::Inherited,
        ]);

        $this->assertTrue(app(PageAccess::class)->canEdit($member, $page));
        $previousRevision = $page->preview_access_revision;

        app(RemoveWorkspaceMember::class)->handle($admin, new RemoveWorkspaceMemberCommand(
            workspaceUid: $root->uid,
            membershipUid: $membership->uid,
            replacementOwnerUserUid: null,
        ));

        $this->assertFalse(app(PageAccess::class)->canView($member, $page->refresh()));
        $this->assertSame($previousRevision + 1, $page->preview_access_revision);
        $this->assertDatabaseHas('workspace_membership_removals', [
            'workspace_uid' => $root->uid,
            'user_uid' => $member->uid,
        ]);
        $this->assertDatabaseHas('workspace_membership_removals', [
            'workspace_uid' => $child->uid,
            'user_uid' => $member->uid,
        ]);
    }

    public function test_removing_a_parent_member_revokes_pending_invitations_across_lost_descendants(): void
    {
        $admin = $this->createUser('Invitation Admin', 'nested-invitation-admin@example.test');
        $member = $this->createUser('Invited Parent Member', 'nested-invitation-member@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($admin, 'Invitation Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Invitation Child');
        app(ReparentWorkspace::class)->handle($admin, new ReparentWorkspaceCommand($child->uid, $root->uid, true));
        $membership = $this->addMember($root, $member, WorkspaceRole::Editor);
        $invitation = WorkspaceInvitation::query()->forceCreate([
            'workspace_uid' => $child->uid,
            'invited_email' => strtolower($member->email),
            'role' => WorkspaceRole::Editor,
            'invited_by_user_uid' => $admin->uid,
            'expires_at' => now()->addDay(),
        ]);

        app(RemoveWorkspaceMember::class)->handle($admin, new RemoveWorkspaceMemberCommand(
            workspaceUid: $root->uid,
            membershipUid: $membership->uid,
            replacementOwnerUserUid: null,
        ));

        $this->assertNotNull($invitation->refresh()->revoked_at);
    }

    public function test_removing_a_parent_membership_preserves_an_independent_child_role(): void
    {
        $admin = $this->createUser('Root Admin', 'nested-preserve-admin@example.test');
        $member = $this->createUser('Elevated Member', 'nested-preserve-member@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($admin, 'Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Child');
        app(ReparentWorkspace::class)->handle($admin, new ReparentWorkspaceCommand($child->uid, $root->uid, true));
        $rootMembership = $this->addMember($root, $member, WorkspaceRole::Editor);
        $this->addMember($child, $member, WorkspaceRole::Reader);
        $page = Page::factory()->create([
            'workspace_uid' => $child->uid,
            'owner_user_uid' => $admin->uid,
            'access_mode' => PageAccessMode::Inherited,
        ]);

        app(RemoveWorkspaceMember::class)->handle($admin, new RemoveWorkspaceMemberCommand(
            workspaceUid: $root->uid,
            membershipUid: $rootMembership->uid,
            replacementOwnerUserUid: null,
        ));

        $this->assertTrue(app(PageAccess::class)->canView($member, $page->refresh()));
        $this->assertFalse(app(PageAccess::class)->canEdit($member, $page));
        $this->assertDatabaseMissing('workspace_membership_removals', [
            'workspace_uid' => $child->uid,
            'user_uid' => $member->uid,
        ]);
    }

    public function test_parent_membership_removal_reassigns_owned_descendant_pages_explicitly(): void
    {
        $admin = $this->createUser('Root Admin', 'nested-owner-admin@example.test');
        $member = $this->createUser('Inherited Owner', 'nested-owner-member@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($admin, 'Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Child');
        app(ReparentWorkspace::class)->handle($admin, new ReparentWorkspaceCommand($child->uid, $root->uid, true));
        $membership = $this->addMember($root, $member, WorkspaceRole::Editor);
        $page = Page::factory()->create([
            'workspace_uid' => $child->uid,
            'owner_user_uid' => $member->uid,
            'access_mode' => PageAccessMode::Inherited,
        ]);

        app(RemoveWorkspaceMember::class)->handle($admin, new RemoveWorkspaceMemberCommand(
            workspaceUid: $root->uid,
            membershipUid: $membership->uid,
            replacementOwnerUserUid: $admin->uid,
        ));

        $this->assertSame($admin->uid, $page->refresh()->owner_user_uid);
    }

    public function test_parent_role_downgrade_is_blocked_only_when_effective_descendant_write_is_lost(): void
    {
        $admin = $this->createUser('Root Admin', 'nested-role-admin@example.test');
        $member = $this->createUser('Inherited Owner', 'nested-role-member@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($admin, 'Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Child');
        app(ReparentWorkspace::class)->handle($admin, new ReparentWorkspaceCommand($child->uid, $root->uid, true));
        $membership = $this->addMember($root, $member, WorkspaceRole::Editor);
        Page::factory()->create([
            'workspace_uid' => $child->uid,
            'owner_user_uid' => $member->uid,
            'access_mode' => PageAccessMode::Inherited,
        ]);
        $handler = app(ChangeWorkspaceMembershipRole::class);
        $command = new ChangeWorkspaceMembershipRoleCommand(
            workspaceUid: $root->uid,
            membershipUid: $membership->uid,
            role: WorkspaceRole::Reader,
        );

        try {
            $handler->handle($admin, $command);
            $this->fail('Expected inherited page ownership to block the downgrade.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame(
                'Reassign pages owned by this member before changing their role to Reader.',
                $exception->getMessage(),
            );
        }

        $this->addMember($child, $member, WorkspaceRole::Editor);
        $updated = $handler->handle($admin, $command);

        $this->assertSame(WorkspaceRole::Reader, $updated->role);
    }

    public function test_parent_role_downgrade_revokes_descendant_invitations_where_authority_is_reduced(): void
    {
        $admin = $this->createUser('Downgrade Invitation Admin', 'downgrade-invitation-admin@example.test');
        $member = $this->createUser('Downgraded Invitee', 'downgrade-invitee@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($admin, 'Downgrade Invitation Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Downgrade Invitation Child');
        app(ReparentWorkspace::class)->handle($admin, new ReparentWorkspaceCommand($child->uid, $root->uid, true));
        $membership = $this->addMember($root, $member, WorkspaceRole::Editor);
        $invitation = WorkspaceInvitation::query()->forceCreate([
            'workspace_uid' => $child->uid,
            'invited_email' => strtolower($member->email),
            'role' => WorkspaceRole::Editor,
            'invited_by_user_uid' => $admin->uid,
            'expires_at' => now()->addDay(),
        ]);

        app(ChangeWorkspaceMembershipRole::class)->handle($admin, new ChangeWorkspaceMembershipRoleCommand(
            workspaceUid: $root->uid,
            membershipUid: $membership->uid,
            role: WorkspaceRole::Reader,
        ));

        $this->assertNotNull($invitation->refresh()->revoked_at);
    }

    public function test_excluding_an_inherited_owner_requires_and_applies_an_explicit_replacement(): void
    {
        $admin = $this->createUser('Exclusion Admin', 'exclusion-owner-admin@example.test');
        $member = $this->createUser('Excluded Owner', 'exclusion-owner-member@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($admin, 'Exclusion Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Exclusion Child', $root->uid);
        $this->addMember($root, $member, WorkspaceRole::Editor);
        $page = Page::factory()->create([
            'workspace_uid' => $child->uid,
            'owner_user_uid' => $member->uid,
            'access_mode' => PageAccessMode::Inherited,
        ]);
        $grant = PageAccessGrant::query()->forceCreate([
            'page_uid' => $page->uid,
            'subject_type' => PageAccessSubjectType::User,
            'subject_uid' => $member->uid,
            'role' => WorkspaceRole::Admin,
            'granted_by_user_uid' => $admin->uid,
        ]);
        $previousRevision = $page->preview_access_revision;
        $handler = app(ExcludeInheritedWorkspaceMember::class);

        try {
            $handler->handle($admin, new ExcludeInheritedWorkspaceMemberCommand(
                workspaceUid: $child->uid,
                memberUserUid: $member->uid,
                replacementOwnerUserUid: null,
            ));
            $this->fail('Expected page ownership to require a replacement.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame(
                'A replacement owner is required for pages owned by this member.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseMissing('workspace_membership_exclusions', [
            'workspace_uid' => $child->uid,
            'user_uid' => $member->uid,
        ]);
        $this->assertSame($member->uid, $page->refresh()->owner_user_uid);

        $handler->handle($admin, new ExcludeInheritedWorkspaceMemberCommand(
            workspaceUid: $child->uid,
            memberUserUid: $member->uid,
            replacementOwnerUserUid: $admin->uid,
        ));

        $this->assertDatabaseHas('workspace_membership_exclusions', [
            'workspace_uid' => $child->uid,
            'user_uid' => $member->uid,
            'excluded_by_user_uid' => $admin->uid,
        ]);
        $this->assertDatabaseHas('workspace_membership_removals', [
            'workspace_uid' => $child->uid,
            'user_uid' => $member->uid,
        ]);
        $this->assertSame($admin->uid, $page->refresh()->owner_user_uid);
        $this->assertSame($previousRevision + 1, $page->preview_access_revision);
        $this->assertDatabaseMissing('page_access_grants', ['uid' => $grant->uid]);
        $this->assertFalse(app(PageAccess::class)->canView($member, $page));

        $exclusionEvent = DomainEvent::query()
            ->where('event_type', 'workspace.membership.inherited_excluded')
            ->sole();
        $this->assertSame(1, $exclusionEvent->payload['reassigned_page_count']);
        $this->assertSame(1, $exclusionEvent->payload['revoked_page_access_grant_count']);

        $grantRevocationEvent = DomainEvent::query()
            ->where('event_type', 'page.access_grant.revoked')
            ->sole();
        $this->assertSame($grant->uid, $grantRevocationEvent->payload['page_access_grant_uid']);
        $this->assertSame('workspace_inherited_member_excluded', $grantRevocationEvent->payload['reason']);

        $exclusionAudit = AuditEntry::query()
            ->where('action', 'workspace.membership.inherited_excluded')
            ->sole();
        $this->assertSame(1, $exclusionAudit->metadata['reassigned_page_count']);
        $this->assertSame(1, $exclusionAudit->metadata['revoked_page_access_grant_count']);
    }

    public function test_exclusion_preserves_direct_descendant_access_and_requires_admin_authority(): void
    {
        $admin = $this->createUser('Boundary Admin', 'boundary-admin@example.test');
        $outsider = $this->createUser('Boundary Outsider', 'boundary-outsider@example.test');
        $member = $this->createUser('Boundary Member', 'boundary-member@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($admin, 'Boundary Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Boundary Child', $root->uid);
        $grandchild = app(CreateSharedWorkspace::class)->handle($admin, 'Boundary Grandchild', $child->uid);
        $this->addMember($root, $member, WorkspaceRole::Editor);
        $this->addMember($grandchild, $member, WorkspaceRole::Reader);
        $handler = app(ExcludeInheritedWorkspaceMember::class);
        $command = new ExcludeInheritedWorkspaceMemberCommand(
            workspaceUid: $child->uid,
            memberUserUid: $member->uid,
            replacementOwnerUserUid: null,
        );

        try {
            $handler->handle($outsider, $command);
            $this->fail('Expected workspace admin authorization.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('workspace_membership_exclusions', [
                'workspace_uid' => $child->uid,
                'user_uid' => $member->uid,
            ]);
        }

        $handler->handle($admin, $command);

        $this->assertFalse(app(PageAccess::class)->workspaceRole($member, $child->uid) instanceof WorkspaceRole);
        $this->assertSame(WorkspaceRole::Reader, app(PageAccess::class)->workspaceRole($member, $grandchild->uid));
        $this->assertDatabaseMissing('workspace_membership_removals', [
            'workspace_uid' => $grandchild->uid,
            'user_uid' => $member->uid,
        ]);
    }

    public function test_direct_membership_overrides_the_local_exclusion_without_resurrecting_a_stronger_parent_role(): void
    {
        $admin = $this->createUser('Restore Admin', 'restore-admin@example.test');
        $member = $this->createUser('Restored Member', 'restored-member@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($admin, 'Restore Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Restore Child', $root->uid);
        $this->addMember($root, $member, WorkspaceRole::Editor);
        app(ExcludeInheritedWorkspaceMember::class)->handle(
            $admin,
            new ExcludeInheritedWorkspaceMemberCommand($child->uid, $member->uid, null),
        );

        $membership = app(AddWorkspaceCollaborator::class)->handle(
            $admin,
            new AddWorkspaceCollaboratorCommand($child->uid, $member->uid, WorkspaceRole::Reader),
        );

        $this->assertSame(WorkspaceRole::Reader, $membership->role);
        $this->assertDatabaseHas('workspace_membership_exclusions', [
            'workspace_uid' => $child->uid,
            'user_uid' => $member->uid,
        ]);
        $this->assertSame(WorkspaceRole::Reader, app(PageAccess::class)->workspaceRole($member, $child->uid));
    }

    private function createUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    private function addMember(Workspace $workspace, User $user, WorkspaceRole $role): WorkspaceMembership
    {
        return WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $user->uid,
            'role' => $role,
            'accepted_at' => now(),
        ]);
    }
}
