<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\AcceptWorkspaceInvitation;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\EffectiveWorkspaceMembershipResolver;
use App\Application\Identity\ExcludeInheritedWorkspaceMember;
use App\Application\Identity\ExcludeInheritedWorkspaceMemberCommand;
use App\Application\Identity\InviteUserToWorkspace;
use App\Application\Identity\InviteUserToWorkspaceCommand;
use App\Application\Identity\ReparentWorkspace;
use App\Application\Identity\ReparentWorkspaceCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class WorkspaceInvitationAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_invited_user_can_accept_a_workspace_invitation(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $invitee = $this->createUser('New Member', 'New.Member@Example.TEST');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'new.member@example.test',
                role: WorkspaceRole::Editor,
            ),
        );

        $membership = app(AcceptWorkspaceInvitation::class)->handle($invitee, $invitation);

        $this->assertSame($workspace->uid, $membership->workspace_uid);
        $this->assertSame($invitee->uid, $membership->user_uid);
        $this->assertSame(WorkspaceRole::Editor, $membership->role);

        $invitation->refresh();
        $this->assertSame($invitee->uid, $invitation->accepted_by_user_uid);
        $this->assertNotNull($invitation->accepted_at);
        $this->assertNotNull($invitation->expires_at);

        $event = DomainEvent::query()
            ->where('event_type', 'workspace.invitation.accepted')
            ->sole();

        $this->assertSame('workspace_invitation', $event->aggregate_type);
        $this->assertSame($invitation->uid, $event->aggregate_uid);
        $this->assertSame($workspace->uid, $event->payload['workspace_uid']);
        $this->assertSame($invitee->uid, $event->payload['accepted_by_user_uid']);
        $this->assertSame('editor', $event->payload['role']);

        $auditEntry = AuditEntry::query()
            ->where('action', 'workspace.invitation.accepted')
            ->sole();

        $this->assertSame($event->uid, $auditEntry->event_uid);
        $this->assertSame($invitee->uid, $auditEntry->actor_user_uid);
        $this->assertSame('workspace_invitation', $auditEntry->auditable_type);
        $this->assertSame($invitation->uid, $auditEntry->auditable_uid);
        $this->assertSame('Workspace invitation accepted.', $auditEntry->summary);
        $this->assertSame($workspace->uid, $auditEntry->metadata['workspace_uid']);
    }

    public function test_acceptance_locks_the_workspace_before_the_invitation_and_membership(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $invitee = $this->createUser('New Member', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: $invitee->email,
                role: WorkspaceRole::Reader,
            ),
        );
        $locks = [];

        DB::listen(function (QueryExecuted $query) use (&$locks, $workspace, $invitation): void {
            $sql = strtolower($query->sql);

            if (!str_contains($sql, 'for update')) {
                return;
            }

            if (str_contains($sql, '"workspaces"') && in_array($workspace->uid, $query->bindings, true)) {
                $locks[] = Workspace::class;
            }

            if (str_contains($sql, '"workspace_invitations"') && in_array($invitation->uid, $query->bindings, true)) {
                $locks[] = WorkspaceInvitation::class;
            }

            if (str_contains($sql, '"workspace_memberships"')) {
                $locks[] = WorkspaceMembership::class;
            }
        });

        app(AcceptWorkspaceInvitation::class)->handle($invitee, $invitation);

        $this->assertSame(
            [Workspace::class, WorkspaceInvitation::class, WorkspaceMembership::class],
            $locks,
        );
    }

    public function test_accepting_an_invitation_twice_by_the_same_user_is_idempotent(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $invitee = $this->createUser('New Member', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'member@example.test',
                role: WorkspaceRole::Reader,
            ),
        );

        $firstMembership = app(AcceptWorkspaceInvitation::class)->handle($invitee, $invitation);
        $secondMembership = app(AcceptWorkspaceInvitation::class)->handle($invitee, $invitation->refresh());

        $this->assertSame($firstMembership->uid, $secondMembership->uid);
        $this->assertSame(1, WorkspaceMembership::query()->where('workspace_uid', $workspace->uid)->where('user_uid', $invitee->uid)->count());
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'workspace.invitation.accepted')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'workspace.invitation.accepted')->count());
    }

    public function test_invitation_is_redundant_against_effective_authority_even_when_a_weaker_direct_membership_exists(): void
    {
        $admin = $this->createUser('Hierarchy Admin', 'invitation-effective-admin@example.test');
        $invitee = $this->createUser('Hierarchy Invitee', 'invitation-effective-member@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($admin, 'Authority Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Authority Child');
        app(ReparentWorkspace::class)->handle($admin, new ReparentWorkspaceCommand($child->uid, $root->uid, true));
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $root->uid,
            'user_uid' => $invitee->uid,
            'role' => WorkspaceRole::Admin,
            'accepted_at' => now(),
        ]);
        $direct = WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $child->uid,
            'user_uid' => $invitee->uid,
            'role' => WorkspaceRole::Reader,
            'accepted_at' => now(),
        ]);
        $invitation = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $child->uid,
                email: $invitee->email,
                role: WorkspaceRole::Editor,
            ),
        );

        try {
            app(AcceptWorkspaceInvitation::class)->handle($invitee, $invitation);
            $this->fail('Expected the invitation to be rejected as redundant.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('This invitation does not add stronger workspace access.', $exception->getMessage());
        }

        $this->assertSame(WorkspaceRole::Reader, $direct->refresh()->role);
        $this->assertNull($invitation->refresh()->accepted_at);
    }

    public function test_excluded_parent_member_can_accept_an_exact_lower_direct_child_role(): void
    {
        $admin = $this->createUser('Exclusion Invitation Admin', 'exclusion-invitation-admin@example.test');
        $invitee = $this->createUser('Excluded Invitee', 'excluded-invitee@example.test');
        $root = app(CreateSharedWorkspace::class)->handle($admin, 'Invitation Exclusion Root');
        $child = app(CreateSharedWorkspace::class)->handle($admin, 'Invitation Exclusion Child', $root->uid);
        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $root->uid,
            'user_uid' => $invitee->uid,
            'role' => WorkspaceRole::Editor,
            'accepted_at' => now(),
        ]);
        app(ExcludeInheritedWorkspaceMember::class)->handle(
            $admin,
            new ExcludeInheritedWorkspaceMemberCommand($child->uid, $invitee->uid, null),
        );
        $invitation = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $child->uid,
                email: $invitee->email,
                role: WorkspaceRole::Reader,
            ),
        );

        $membership = app(AcceptWorkspaceInvitation::class)->handle($invitee, $invitation);

        $this->assertSame(WorkspaceRole::Reader, $membership->role);
        $this->assertSame(
            WorkspaceRole::Reader,
            app(EffectiveWorkspaceMembershipResolver::class)->resolve($invitee->uid, $child->uid)->role,
        );
        $this->assertDatabaseHas('workspace_membership_exclusions', [
            'workspace_uid' => $child->uid,
            'user_uid' => $invitee->uid,
        ]);
    }

    public function test_weaker_invitation_is_rejected_when_a_direct_admin_membership_already_exists(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $invitee = $this->createUser('Existing Admin', 'existing-admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'existing-admin@example.test',
                role: WorkspaceRole::Reader,
            ),
        );

        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $invitee->uid,
            'role' => WorkspaceRole::Admin,
            'accepted_at' => now(),
        ]);

        try {
            app(AcceptWorkspaceInvitation::class)->handle($invitee, $invitation);
            $this->fail('Expected the weaker invitation to be rejected as redundant.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('This invitation does not add stronger workspace access.', $exception->getMessage());
        }

        $membership = WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $invitee->uid)
            ->sole();
        $this->assertSame(WorkspaceRole::Admin, $membership->role);
        $this->assertNull($invitation->refresh()->accepted_at);
    }

    public function test_only_the_invited_email_can_accept_an_invitation(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $otherUser = $this->createUser('Other User', 'other@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'member@example.test',
                role: WorkspaceRole::Reader,
            ),
        );

        try {
            app(AcceptWorkspaceInvitation::class)->handle($otherUser, $invitation);
            $this->fail('Expected an invitation email mismatch to be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Only the invited user can accept this workspace invitation.', $exception->getMessage());
        }

        $this->assertSame(0, WorkspaceMembership::query()->where('workspace_uid', $workspace->uid)->where('user_uid', $otherUser->uid)->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'workspace.invitation.accepted')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'workspace.invitation.accepted')->count());
    }

    public function test_revoked_invitations_cannot_be_accepted(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $invitee = $this->createUser('Member User', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'member@example.test',
                role: WorkspaceRole::Reader,
            ),
        );
        $invitation->forceFill(['revoked_at' => now()])->save();

        try {
            app(AcceptWorkspaceInvitation::class)->handle($invitee, $invitation->refresh());
            $this->fail('Expected revoked invitation acceptance to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Revoked workspace invitations cannot be accepted.', $exception->getMessage());
        }

        $this->assertSame(0, DomainEvent::query()->where('event_type', 'workspace.invitation.accepted')->count());
    }

    public function test_expired_invitations_cannot_be_accepted(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $invitee = $this->createUser('Member User', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'member@example.test',
                role: WorkspaceRole::Reader,
            ),
        );
        $invitation->forceFill(['expires_at' => now()->subMinute()])->save();

        try {
            app(AcceptWorkspaceInvitation::class)->handle($invitee, $invitation->refresh());
            $this->fail('Expected expired invitation acceptance to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Expired workspace invitations cannot be accepted.', $exception->getMessage());
        }

        $this->assertSame(0, DomainEvent::query()->where('event_type', 'workspace.invitation.accepted')->count());
    }

    public function test_invitations_expire_after_the_configured_ttl_and_legacy_null_expiry_is_inactive(): void
    {
        config(['pages.workspace_invitation_ttl_days' => 7]);

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $invitee = $this->createUser('Member User', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'member@example.test',
                role: WorkspaceRole::Reader,
            ),
        );

        $this->assertNotNull($invitation->expires_at);

        $this->travel(8)->days();

        try {
            app(AcceptWorkspaceInvitation::class)->handle($invitee, $invitation->refresh());
            $this->fail('Expected TTL-expired invitation acceptance to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Expired workspace invitations cannot be accepted.', $exception->getMessage());
        }

        $legacyInvitation = WorkspaceInvitation::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'invited_email' => 'legacy@example.test',
            'role' => WorkspaceRole::Admin,
            'invited_by_user_uid' => $admin->uid,
            'expires_at' => null,
        ]);
        $legacyInvitee = $this->createUser('Legacy User', 'legacy@example.test');

        try {
            app(AcceptWorkspaceInvitation::class)->handle($legacyInvitee, $legacyInvitation);
            $this->fail('Expected legacy null-expiry invitation acceptance to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Expired workspace invitations cannot be accepted.', $exception->getMessage());
        }

        $this->assertSame(0, WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $invitee->uid)
            ->count());
        $this->assertSame(0, WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $legacyInvitee->uid)
            ->count());
    }

    private function createUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }
}
