<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\AcceptWorkspaceInvitation;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\InviteUserToWorkspace;
use App\Application\Identity\InviteUserToWorkspaceCommand;
use App\Application\Identity\RevokeWorkspaceInvitation;
use App\Application\Identity\RevokeWorkspaceInvitationCommand;
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

final class WorkspaceInvitationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_admin_can_revoke_a_pending_invitation_with_traceability_and_idempotency(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = $this->invite($admin, $workspace->uid, 'member@example.test', WorkspaceRole::Editor);
        $command = new RevokeWorkspaceInvitationCommand(
            workspaceUid: $workspace->uid,
            invitationUid: $invitation->uid,
        );
        $handler = app(RevokeWorkspaceInvitation::class);

        $revokedInvitation = $handler->handle($admin, $command);
        $repeatedInvitation = $handler->handle($admin, $command);

        $this->assertNotNull($revokedInvitation->revoked_at);
        $this->assertNotNull($repeatedInvitation->revoked_at);
        $this->assertSame(
            $revokedInvitation->revoked_at->getTimestamp(),
            $repeatedInvitation->revoked_at->getTimestamp(),
        );

        $event = DomainEvent::query()
            ->where('event_type', 'workspace.invitation.revoked')
            ->sole();

        $this->assertSame('workspace_invitation', $event->aggregate_type);
        $this->assertSame($invitation->uid, $event->aggregate_uid);
        $this->assertSame($workspace->uid, $event->payload['workspace_uid']);
        $this->assertSame($admin->uid, $event->payload['revoked_by_user_uid']);
        $this->assertSame('editor', $event->payload['role']);

        $audit = AuditEntry::query()
            ->where('action', 'workspace.invitation.revoked')
            ->sole();

        $this->assertSame($event->uid, $audit->event_uid);
        $this->assertSame($admin->uid, $audit->actor_user_uid);
        $this->assertSame('workspace_invitation', $audit->auditable_type);
        $this->assertSame($invitation->uid, $audit->auditable_uid);
        $this->assertSame($workspace->uid, $audit->metadata['workspace_uid']);
        $this->assertSame('editor', $audit->metadata['role']);
    }

    public function test_non_admin_and_outsider_cannot_revoke_workspace_invitations(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $outsider = $this->createUser('Outside User', 'outside@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);
        $invitation = $this->invite($admin, $workspace->uid, 'member@example.test', WorkspaceRole::Reader);
        $command = new RevokeWorkspaceInvitationCommand(
            workspaceUid: $workspace->uid,
            invitationUid: $invitation->uid,
        );

        foreach ([$editor, $outsider] as $actor) {
            try {
                app(RevokeWorkspaceInvitation::class)->handle($actor, $command);
                $this->fail('Expected invitation revocation to be forbidden.');
            } catch (AuthorizationException $exception) {
                $this->assertSame('Only workspace admins can revoke invitations.', $exception->getMessage());
            }
        }

        $this->assertNull($invitation->refresh()->revoked_at);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'workspace.invitation.revoked')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'workspace.invitation.revoked')->count());
    }

    public function test_editor_with_invitation_permission_can_revoke_only_their_own_pending_invitation(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $editor = $this->createUser('Editor User', 'editor@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $workspace->forceFill(['allow_editor_invites' => true])->save();
        $this->addMember($workspace->uid, $editor, WorkspaceRole::Editor);
        $adminInvitation = $this->invite($admin, $workspace->uid, 'admin-invite@example.test', WorkspaceRole::Reader);
        $editorInvitation = $this->invite($editor, $workspace->uid, 'editor-invite@example.test', WorkspaceRole::Editor);

        app(RevokeWorkspaceInvitation::class)->handle($editor, new RevokeWorkspaceInvitationCommand(
            workspaceUid: $workspace->uid,
            invitationUid: $editorInvitation->uid,
        ));

        try {
            app(RevokeWorkspaceInvitation::class)->handle($editor, new RevokeWorkspaceInvitationCommand(
                workspaceUid: $workspace->uid,
                invitationUid: $adminInvitation->uid,
            ));
            $this->fail('Expected editor not to revoke another actor invitation.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Editors can revoke only invitations they created.', $exception->getMessage());
        }

        $this->assertNotNull($editorInvitation->refresh()->revoked_at);
        $this->assertNull($adminInvitation->refresh()->revoked_at);
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'workspace.invitation.revoked')->count());
    }

    public function test_accepted_invitations_cannot_be_revoked_or_reinvited(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $invitee = $this->createUser('Member User', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = $this->invite($admin, $workspace->uid, $invitee->email, WorkspaceRole::Reader);
        app(AcceptWorkspaceInvitation::class)->handle($invitee, $invitation);

        try {
            app(RevokeWorkspaceInvitation::class)->handle($admin, new RevokeWorkspaceInvitationCommand(
                workspaceUid: $workspace->uid,
                invitationUid: $invitation->uid,
            ));
            $this->fail('Expected accepted invitation revocation to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('Accepted workspace invitations cannot be revoked.', $exception->getMessage());
        }

        try {
            app(InviteUserToWorkspace::class)->handle(
                actor: $admin,
                command: new InviteUserToWorkspaceCommand(
                    workspaceUid: $workspace->uid,
                    email: $invitee->email,
                    role: WorkspaceRole::Editor,
                ),
            );
            $this->fail('Expected accepted invitations not to be silently reactivated.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame(
                'This workspace invitation has already been accepted. Change the member role instead.',
                $exception->getMessage(),
            );
        }

        $this->assertNull($invitation->refresh()->revoked_at);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'workspace.invitation.revoked')->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'workspace.invitation.reactivated')->count());
    }

    public function test_reinviting_a_revoked_invitation_reactivates_the_same_uid_and_can_be_accepted(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $invitee = $this->createUser('Member User', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = $this->invite($admin, $workspace->uid, $invitee->email, WorkspaceRole::Reader);

        app(RevokeWorkspaceInvitation::class)->handle($admin, new RevokeWorkspaceInvitationCommand(
            workspaceUid: $workspace->uid,
            invitationUid: $invitation->uid,
        ));
        $reactivated = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: strtoupper($invitee->email),
                role: WorkspaceRole::Editor,
            ),
        );

        $this->assertSame($invitation->uid, $reactivated->uid);
        $this->assertSame(WorkspaceRole::Editor, $reactivated->role);
        $this->assertNull($reactivated->revoked_at);
        $this->assertNotNull($reactivated->expires_at);
        $this->assertNull($reactivated->accepted_at);
        $this->assertNull($reactivated->accepted_by_user_uid);

        $event = DomainEvent::query()
            ->where('event_type', 'workspace.invitation.reactivated')
            ->sole();

        $this->assertSame($invitation->uid, $event->aggregate_uid);
        $this->assertSame('revoked', $event->payload['previous_state']);
        $this->assertSame('reader', $event->payload['previous_role']);
        $this->assertSame('editor', $event->payload['new_role']);

        $audit = AuditEntry::query()
            ->where('action', 'workspace.invitation.reactivated')
            ->sole();

        $this->assertSame($event->uid, $audit->event_uid);
        $this->assertSame('revoked', $audit->metadata['previous_state']);

        $membership = app(AcceptWorkspaceInvitation::class)->handle($invitee, $reactivated);
        $this->assertSame(WorkspaceRole::Editor, $membership->role);
    }

    public function test_reactivating_a_revoked_invitation_rotates_the_link_token(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $invitee = $this->createUser('Member User', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = $this->invite($admin, $workspace->uid, $invitee->email, WorkspaceRole::Reader);
        $originalToken = $invitation->token;

        app(RevokeWorkspaceInvitation::class)->handle($admin, new RevokeWorkspaceInvitationCommand(
            workspaceUid: $workspace->uid,
            invitationUid: $invitation->uid,
        ));

        $reactivated = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: $invitee->email,
                role: WorkspaceRole::Editor,
            ),
        );

        // The revoked link may have leaked. Reactivation must invalidate it: the token is
        // rotated, so the old secret resolves to nothing while the reissued one is live.
        $this->assertNotSame($originalToken, $reactivated->token);
        $this->assertFalse(
            WorkspaceInvitation::query()->where('token', $originalToken)->exists(),
            'The revoked invitation link token must no longer resolve after reactivation.',
        );
        $this->assertTrue(WorkspaceInvitation::query()->where('token', $reactivated->token)->whereNull('revoked_at')->exists());
    }

    public function test_reinviting_an_expired_invitation_reactivates_it_with_traceability(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = $this->invite($admin, $workspace->uid, 'member@example.test', WorkspaceRole::Reader);
        $invitation->forceFill(['expires_at' => now()->subMinute()])->save();

        $reactivated = app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: 'member@example.test',
                role: WorkspaceRole::Admin,
            ),
        );

        $this->assertSame($invitation->uid, $reactivated->uid);
        $this->assertNotNull($reactivated->expires_at);
        $this->assertSame(WorkspaceRole::Admin, $reactivated->role);

        $event = DomainEvent::query()
            ->where('event_type', 'workspace.invitation.reactivated')
            ->sole();
        $this->assertSame('expired', $event->payload['previous_state']);
        $this->assertSame('admin', $event->payload['new_role']);
    }

    public function test_admin_can_revoke_from_dashboard_and_cross_workspace_routes_are_hidden(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $invitee = $this->createUser('Member User', 'member@example.test');
        $otherAdmin = $this->createUser('Other Admin', 'other-admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $otherWorkspace = app(CreateSharedWorkspace::class)->handle($otherAdmin, 'Other Team');
        $invitation = $this->invite($admin, $workspace->uid, $invitee->email, WorkspaceRole::Reader);

        $this->delete("/workspaces/{$workspace->uid}/invitations/{$invitation->uid}")
            ->assertRedirect('/login');

        $this->actingAs($admin)
            ->withSession(['current_workspace_uid' => $workspace->uid])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee("workspaces/{$workspace->uid}/invitations/{$invitation->uid}", false)
            ->assertSee('Revoke');

        $this->actingAs($admin)
            ->delete("/workspaces/{$otherWorkspace->uid}/invitations/{$invitation->uid}")
            ->assertNotFound();

        $this->actingAs($admin)
            ->delete("/workspaces/{$workspace->uid}/invitations/{$invitation->uid}")
            ->assertRedirect('/dashboard')
            ->assertSessionHas('status', 'Workspace invitation revoked.')
            ->assertSessionHas('current_workspace_uid', $workspace->uid);

        $this->actingAs($invitee)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Platform Team');
    }

    private function createUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
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

    private function invite(
        User $admin,
        string $workspaceUid,
        string $email,
        WorkspaceRole $role,
    ): WorkspaceInvitation {
        $workspace = Workspace::query()->findOrFail($workspaceUid);

        return app(InviteUserToWorkspace::class)->handle(
            actor: $admin,
            command: new InviteUserToWorkspaceCommand(
                workspaceUid: $workspace->uid,
                email: $email,
                role: $role,
            ),
        );
    }
}
