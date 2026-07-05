<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\InviteUserToWorkspace;
use App\Application\Identity\InviteUserToWorkspaceCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\Identity\WorkspaceRole;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class WorkspaceInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_predicate_and_scope_share_the_same_lifecycle_rules(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $pending = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'pending@example.test',
                role: WorkspaceRole::Reader,
            ),
        );
        $accepted = WorkspaceInvitation::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'invited_email' => 'accepted@example.test',
            'role' => WorkspaceRole::Reader,
            'invited_by_user_uid' => $admin->uid,
            'accepted_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        $revoked = WorkspaceInvitation::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'invited_email' => 'revoked@example.test',
            'role' => WorkspaceRole::Reader,
            'invited_by_user_uid' => $admin->uid,
            'revoked_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
        $expired = WorkspaceInvitation::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'invited_email' => 'expired@example.test',
            'role' => WorkspaceRole::Reader,
            'invited_by_user_uid' => $admin->uid,
            'expires_at' => now()->subSecond(),
        ]);

        $this->assertTrue($pending->isPending());
        $this->assertFalse($accepted->isPending());
        $this->assertFalse($revoked->isPending());
        $this->assertFalse($expired->isPending());
        $this->assertSame(
            [$pending->uid],
            WorkspaceInvitation::query()->pending()->pluck('uid')->all(),
        );
    }

    public function test_workspace_admin_can_invite_a_user_by_email(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');

        $invitation = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: '  New.Member@Example.TEST  ',
                role: WorkspaceRole::Editor,
            ),
        );

        $this->assertSame($workspace->uid, $invitation->workspace_uid);
        $this->assertSame('new.member@example.test', $invitation->invited_email);
        $this->assertSame(WorkspaceRole::Editor, $invitation->role);
        $this->assertSame($admin->uid, $invitation->invited_by_user_uid);
        $this->assertNull($invitation->accepted_at);
        $this->assertNull($invitation->revoked_at);

        $event = DomainEvent::query()
            ->where('event_type', 'workspace.invitation.created')
            ->sole();

        $this->assertSame('workspace_invitation', $event->aggregate_type);
        $this->assertSame($invitation->uid, $event->aggregate_uid);
        $this->assertSame($workspace->uid, $event->payload['workspace_uid']);
        $this->assertSame($admin->uid, $event->payload['invited_by_user_uid']);
        $this->assertSame('new.member@example.test', $event->payload['invited_email']);
        $this->assertSame('editor', $event->payload['role']);

        $auditEntry = AuditEntry::query()
            ->where('action', 'workspace.invitation.created')
            ->sole();

        $this->assertSame($event->uid, $auditEntry->event_uid);
        $this->assertSame($admin->uid, $auditEntry->actor_user_uid);
        $this->assertSame('workspace_invitation', $auditEntry->auditable_type);
        $this->assertSame($invitation->uid, $auditEntry->auditable_uid);
        $this->assertSame('Workspace invitation created.', $auditEntry->summary);
        $this->assertSame($workspace->uid, $auditEntry->metadata['workspace_uid']);
        $this->assertSame('editor', $auditEntry->metadata['role']);
    }

    public function test_non_admin_workspace_members_cannot_invite_users(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');

        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $editor->uid,
            'role' => WorkspaceRole::Editor,
            'accepted_at' => now(),
        ]);

        try {
            app(InviteUserToWorkspace::class)->handle(
                actor: $editor,
                command: new InviteUserToWorkspaceCommand(
                    workspaceUid: $workspace->uid,
                    email: 'reader@example.test',
                    role: WorkspaceRole::Reader,
                ),
            );
            $this->fail('Expected a non-admin workspace member to be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Only workspace admins can invite members.', $exception->getMessage());
        }

        $this->assertSame(0, WorkspaceInvitation::query()->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'workspace.invitation.created')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'workspace.invitation.created')->count());
    }

    public function test_editor_can_invite_non_admin_members_only_when_workspace_setting_allows_it(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $workspace->forceFill(['allow_editor_invites' => true])->save();

        WorkspaceMembership::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'user_uid' => $editor->uid,
            'role' => WorkspaceRole::Editor,
            'accepted_at' => now(),
        ]);

        $invitation = app(InviteUserToWorkspace::class)->handle(
            actor: $editor,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'reader@example.test',
                role: WorkspaceRole::Reader,
            ),
        );

        $this->assertSame($editor->uid, $invitation->invited_by_user_uid);
        $this->assertSame(WorkspaceRole::Reader, $invitation->role);

        try {
            app(InviteUserToWorkspace::class)->handle(
                actor: $editor,
                command: new InviteUserToWorkspaceCommand(
                    workspaceUid: $workspace->uid,
                    email: 'admin-target@example.test',
                    role: WorkspaceRole::Admin,
                ),
            );
            $this->fail('Expected editor invitation to Admin to be rejected.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Editors cannot invite workspace admins.', $exception->getMessage());
        }

        $this->assertSame(1, WorkspaceInvitation::query()->count());
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'workspace.invitation.created')->count());
    }

    public function test_personal_workspaces_do_not_accept_invitations(): void
    {
        $owner = $this->createUser('Owner User', 'owner@example.test');
        $workspace = Workspace::query()
            ->where('personal_owner_uid', $owner->uid)
            ->sole();

        try {
            app(InviteUserToWorkspace::class)->handle(
                actor: $owner,
                command: new InviteUserToWorkspaceCommand(
                    workspaceUid: $workspace->uid,
                    email: 'friend@example.test',
                    role: WorkspaceRole::Reader,
                ),
            );
            $this->fail('Expected personal workspace invitations to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Personal workspaces cannot invite members.', $exception->getMessage());
        }

        $this->assertSame(0, WorkspaceInvitation::query()->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'workspace.invitation.created')->count());
    }

    public function test_invalid_invitation_emails_are_rejected(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');

        try {
            app(InviteUserToWorkspace::class)->handle(
                actor: $admin,
                command: new InviteUserToWorkspaceCommand(
                    workspaceUid: $workspace->uid,
                    email: 'not an email',
                    role: WorkspaceRole::Reader,
                ),
            );
            $this->fail('Expected an invalid invitation email to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Invitation email must be a valid email address.', $exception->getMessage());
        }

        $this->assertSame(0, WorkspaceInvitation::query()->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'workspace.invitation.created')->count());
    }

    public function test_reinviting_the_same_email_with_the_same_role_is_idempotent(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');

        $firstInvitation = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'reader@example.test',
                role: WorkspaceRole::Reader,
            ),
        );
        $secondInvitation = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'READER@example.test',
                role: WorkspaceRole::Reader,
            ),
        );

        $this->assertSame($firstInvitation->uid, $secondInvitation->uid);
        $this->assertSame(1, WorkspaceInvitation::query()->count());
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'workspace.invitation.created')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'workspace.invitation.created')->count());
    }

    public function test_reinviting_the_same_email_with_a_new_role_updates_the_invitation_with_traceability(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');

        $invitation = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'member@example.test',
                role: WorkspaceRole::Reader,
            ),
        );
        $updatedInvitation = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'member@example.test',
                role: WorkspaceRole::Admin,
            ),
        );

        $this->assertSame($invitation->uid, $updatedInvitation->uid);
        $this->assertSame(WorkspaceRole::Admin, $updatedInvitation->role);

        $event = DomainEvent::query()
            ->where('event_type', 'workspace.invitation.role_changed')
            ->sole();

        $this->assertSame($invitation->uid, $event->aggregate_uid);
        $this->assertSame('reader', $event->payload['previous_role']);
        $this->assertSame('admin', $event->payload['new_role']);

        $auditEntry = AuditEntry::query()
            ->where('action', 'workspace.invitation.role_changed')
            ->sole();

        $this->assertSame($event->uid, $auditEntry->event_uid);
        $this->assertSame($admin->uid, $auditEntry->actor_user_uid);
        $this->assertSame($invitation->uid, $auditEntry->auditable_uid);
        $this->assertSame('Workspace invitation role changed.', $auditEntry->summary);
        $this->assertSame('reader', $auditEntry->metadata['previous_role']);
        $this->assertSame('admin', $auditEntry->metadata['new_role']);
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
}
