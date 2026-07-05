<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\CreateSharedWorkspace;
use App\Domain\Identity\WorkspaceRole;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Mockery;
use Tests\TestCase;

final class WorkspaceInvitationHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_admin_can_invite_a_member_from_the_dashboard(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');

        $this->actingAs($admin)
            ->withSession(['current_workspace_uid' => $workspace->uid])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Invite teammate')
            ->assertSee('data-open-editor-dialog="workspace-invite-dialog"', false)
            ->assertSee('id="workspace-invite-dialog"', false)
            ->assertSee('Pending invitations')
            ->assertSee('/workspaces/' . $workspace->uid . '/invitations', false);

        $this->actingAs($admin)
            ->withSession(['current_workspace_uid' => $workspace->uid])
            ->post("/workspaces/{$workspace->uid}/invitations", [
                'email' => 'New.Member@Example.TEST',
                'role' => WorkspaceRole::Editor->value,
            ])
            ->assertRedirect('/dashboard')
            ->assertSessionHas('status', 'Workspace invitation sent.');

        $invitation = WorkspaceInvitation::query()->sole();
        $this->assertSame($workspace->uid, $invitation->workspace_uid);
        $this->assertSame('new.member@example.test', $invitation->invited_email);
        $this->assertSame(WorkspaceRole::Editor, $invitation->role);
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'workspace.invitation.created')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'workspace.invitation.created')->count());

        $this->actingAs($admin)
            ->withSession(['current_workspace_uid' => $workspace->uid])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('new.member@example.test')
            ->assertSee('editor');
    }

    public function test_workspace_admin_can_invite_a_member_from_the_selected_library_workspace(): void
    {
        $admin = $this->createUser('Library Admin', 'library-admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Library Team');

        $this->actingAs($admin)
            ->get("/pages?workspace_uid={$workspace->uid}")
            ->assertOk()
            ->assertSee('Invite teammate')
            ->assertSee('data-open-editor-dialog="library-workspace-invite-dialog"', false)
            ->assertSee('id="library-workspace-invite-dialog"', false)
            ->assertSee("/workspaces/{$workspace->uid}/invitations", false)
            ->assertSee('name="return_to"', false)
            ->assertSee('value="library"', false);

        $this->actingAs($admin)
            ->post("/workspaces/{$workspace->uid}/invitations", [
                'email' => 'library-member@example.test',
                'role' => WorkspaceRole::Reader->value,
                'return_to' => 'library',
            ])
            ->assertRedirect(route('pages.index', ['workspace_uid' => $workspace->uid]))
            ->assertSessionHas('status', 'Workspace invitation sent.')
            ->assertSessionHas('current_workspace_uid', $workspace->uid);

        $this->assertDatabaseHas('workspace_invitations', [
            'workspace_uid' => $workspace->uid,
            'invited_email' => 'library-member@example.test',
            'role' => WorkspaceRole::Reader->value,
        ]);

        $this->actingAs($admin)
            ->get('/pages?workspace_uid=all')
            ->assertOk()
            ->assertDontSee('data-open-editor-dialog="library-workspace-invite-dialog"', false);
    }

    public function test_non_admins_cannot_invite_members_or_see_invitation_controls(): void
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

        $this->actingAs($editor)
            ->withSession(['current_workspace_uid' => $workspace->uid])
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Invite teammate');

        $this->actingAs($editor)
            ->post("/workspaces/{$workspace->uid}/invitations", [
                'email' => 'reader@example.test',
                'role' => WorkspaceRole::Reader->value,
            ])
            ->assertForbidden();

        $this->assertSame(0, WorkspaceInvitation::query()->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'workspace.invitation.created')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'workspace.invitation.created')->count());
    }

    public function test_editor_invitation_setting_exposes_limited_controls_and_rejects_admin_escalation(): void
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

        $this->actingAs($editor)
            ->withSession(['current_workspace_uid' => $workspace->uid])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Invite teammate')
            ->assertSee('<option value="reader">Reader</option>', false)
            ->assertSee('<option value="editor">Editor</option>', false)
            ->assertDontSee('<option value="admin">Admin</option>', false);

        $this->actingAs($editor)
            ->post("/workspaces/{$workspace->uid}/invitations", [
                'email' => 'reader@example.test',
                'role' => WorkspaceRole::Reader->value,
            ])
            ->assertRedirect('/dashboard');

        $this->actingAs($editor)
            ->post("/workspaces/{$workspace->uid}/invitations", [
                'email' => 'admin-target@example.test',
                'role' => WorkspaceRole::Admin->value,
            ])
            ->assertForbidden();

        $this->assertSame(1, WorkspaceInvitation::query()->count());
    }

    public function test_invitation_http_boundary_validates_email_role_and_shared_workspace(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $personalWorkspace = Workspace::query()
            ->where('personal_owner_uid', $admin->uid)
            ->sole();

        $this->actingAs($admin)
            ->post("/workspaces/{$workspace->uid}/invitations", [
                'email' => 'not an email',
                'role' => 'owner',
            ])
            ->assertSessionHasErrors(['email', 'role']);

        $this->actingAs($admin)
            ->post("/workspaces/{$personalWorkspace->uid}/invitations", [
                'email' => 'reader@example.test',
                'role' => WorkspaceRole::Reader->value,
            ])
            ->assertForbidden();

        $this->assertSame(0, WorkspaceInvitation::query()->count());
    }

    public function test_invited_user_can_see_and_accept_their_pending_invitation(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $invitee = $this->createUser('Invited User', 'member@example.test');
        $otherUser = $this->createUser('Other User', 'other@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');

        $this->actingAs($admin)
            ->post("/workspaces/{$workspace->uid}/invitations", [
                'email' => 'member@example.test',
                'role' => WorkspaceRole::Reader->value,
            ]);

        $invitation = WorkspaceInvitation::query()->sole();

        $this->actingAs($invitee)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Workspace invitations')
            ->assertSee('Platform Team')
            ->assertSee('/workspace-invitations/' . $invitation->uid . '/accept', false);

        $this->actingAs($otherUser)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Platform Team');

        $this->actingAs($otherUser)
            ->post("/workspace-invitations/{$invitation->uid}/accept")
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors(['invitation' => 'Workspace invitation cannot be accepted.']);

        $this->actingAs($invitee)
            ->post("/workspace-invitations/{$invitation->uid}/accept")
            ->assertRedirect('/dashboard')
            ->assertSessionHas('current_workspace_uid', $workspace->uid)
            ->assertSessionHas('status', 'Workspace invitation accepted.');

        $membership = WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $invitee->uid)
            ->sole();
        $this->assertSame(WorkspaceRole::Reader, $membership->role);
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'workspace.invitation.accepted')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'workspace.invitation.accepted')->count());
    }

    public function test_invitation_acceptance_enforces_the_policy_backstop_before_the_handler(): void
    {
        $admin = $this->createUser('Admin User', 'policy-admin@example.test');
        $invitee = $this->createUser('Invited User', 'policy-invitee@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Policy Team');

        $this->actingAs($admin)
            ->post("/workspaces/{$workspace->uid}/invitations", [
                'email' => $invitee->email,
                'role' => WorkspaceRole::Reader->value,
            ]);

        $invitation = WorkspaceInvitation::query()->sole();
        Gate::shouldReceive('authorize')
            ->once()
            ->with('accept', Mockery::on(
                static fn (WorkspaceInvitation $authorizedInvitation): bool => $authorizedInvitation->uid === $invitation->uid,
            ))
            ->andThrow(new AuthorizationException('Policy denied for regression test.'));

        $this->actingAs($invitee)
            ->post("/workspace-invitations/{$invitation->uid}/accept")
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors(['invitation' => 'Workspace invitation cannot be accepted.']);

        $this->assertSame(0, WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $invitee->uid)
            ->count());
    }

    public function test_invitation_accept_endpoint_has_a_dedicated_rate_limiter(): void
    {
        config([
            'rate_limits.authenticated_per_minute' => 100,
            'rate_limits.invitation_accepts_per_minute' => 2,
        ]);

        $admin = $this->createUser('Admin User', 'admin@example.test');
        $invitee = $this->createUser('Invited User', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');

        $this->actingAs($admin)
            ->post("/workspaces/{$workspace->uid}/invitations", [
                'email' => 'member@example.test',
                'role' => WorkspaceRole::Reader->value,
            ]);

        $invitation = WorkspaceInvitation::query()->sole();

        $this->actingAs($invitee)
            ->post("/workspace-invitations/{$invitation->uid}/accept")
            ->assertRedirect('/dashboard');

        $this->actingAs($invitee)
            ->post("/workspace-invitations/{$invitation->uid}/accept")
            ->assertRedirect('/dashboard');

        $this->actingAs($invitee)
            ->post("/workspace-invitations/{$invitation->uid}/accept")
            ->assertTooManyRequests();

        $this->assertSame(1, DomainEvent::query()->where('event_type', 'workspace.invitation.accepted')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'workspace.invitation.accepted')->count());
    }

    public function test_invitation_acceptance_uses_a_generic_http_error_for_invalid_or_unauthorized_uids(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $invitee = $this->createUser('Invited User', 'member@example.test');
        $otherUser = $this->createUser('Other User', 'other@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');

        $this->actingAs($admin)
            ->post("/workspaces/{$workspace->uid}/invitations", [
                'email' => 'member@example.test',
                'role' => WorkspaceRole::Reader->value,
            ]);

        $invitation = WorkspaceInvitation::query()->sole();

        $this->actingAs($otherUser)
            ->post("/workspace-invitations/{$invitation->uid}/accept")
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors(['invitation' => 'Workspace invitation cannot be accepted.']);

        $invitation->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->actingAs($invitee)
            ->post("/workspace-invitations/{$invitation->uid}/accept")
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors(['invitation' => 'Workspace invitation cannot be accepted.']);

        $invitation->forceFill([
            'expires_at' => now()->addDay(),
            'revoked_at' => now(),
        ])->save();

        $this->actingAs($invitee)
            ->post("/workspace-invitations/{$invitation->uid}/accept")
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors(['invitation' => 'Workspace invitation cannot be accepted.']);

        $this->actingAs($invitee)
            ->post('/workspace-invitations/01ARZ3NDEKTSV4RRFFQ69G5FAV/accept')
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors(['invitation' => 'Workspace invitation cannot be accepted.']);

        $this->assertSame(0, WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $invitee->uid)
            ->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'workspace.invitation.accepted')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'workspace.invitation.accepted')->count());
    }

    public function test_emailed_invitation_link_opens_a_confirmation_page_the_invited_user_can_submit(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $invitee = $this->createUser('Invited User', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');

        $this->actingAs($admin)
            ->post("/workspaces/{$workspace->uid}/invitations", [
                'email' => 'member@example.test',
                'role' => WorkspaceRole::Reader->value,
            ]);

        $invitation = WorkspaceInvitation::query()->sole();

        // The link delivered by email is a GET request. It must resolve to a
        // confirmation page, not a 405 against the POST-only accept endpoint.
        $this->actingAs($invitee)
            ->get(route('workspace-invitations.show', $invitation))
            ->assertOk()
            ->assertSee('Platform Team')
            ->assertSee('action="' . route('workspace-invitations.accept', $invitation) . '"', false);

        $this->actingAs($invitee)
            ->post("/workspace-invitations/{$invitation->uid}/accept")
            ->assertRedirect('/dashboard')
            ->assertSessionHas('status', 'Workspace invitation accepted.');

        $this->assertSame(1, WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $invitee->uid)
            ->count());
    }

    public function test_legacy_get_accept_link_redirects_to_the_confirmation_page_without_accepting(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $invitee = $this->createUser('Invited User', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');

        $this->actingAs($admin)
            ->post("/workspaces/{$workspace->uid}/invitations", [
                'email' => 'member@example.test',
                'role' => WorkspaceRole::Reader->value,
            ]);

        $invitation = WorkspaceInvitation::query()->sole();

        // Emails delivered before the POST-confirmation change link to GET /accept.
        // That URL is frozen in the recipient's inbox, so it must resolve to the
        // confirmation page instead of a 405 — and must not accept on GET.
        $this->actingAs($invitee)
            ->get("/workspace-invitations/{$invitation->uid}/accept")
            ->assertRedirect(route('workspace-invitations.show', $invitation));

        $this->assertSame(0, WorkspaceMembership::query()
            ->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $invitee->uid)
            ->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'workspace.invitation.accepted')->count());
    }

    public function test_legacy_get_accept_link_for_a_missing_invitation_redirects_to_the_dashboard(): void
    {
        $invitee = $this->createUser('Invited User', 'member@example.test');

        $this->actingAs($invitee)
            ->get('/workspace-invitations/01ARZ3NDEKTSV4RRFFQ69G5FAV/accept')
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors(['invitation' => 'Workspace invitation cannot be accepted.']);
    }

    public function test_invitation_confirmation_page_is_hidden_from_users_who_were_not_invited(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $otherUser = $this->createUser('Other User', 'other@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');

        $this->actingAs($admin)
            ->post("/workspaces/{$workspace->uid}/invitations", [
                'email' => 'member@example.test',
                'role' => WorkspaceRole::Reader->value,
            ]);

        $invitation = WorkspaceInvitation::query()->sole();

        // A non-invited (but authenticated) user must never see the workspace
        // name; the confirmation page redirects them out instead of rendering.
        $this->actingAs($otherUser)
            ->get(route('workspace-invitations.show', $invitation))
            ->assertRedirect('/dashboard')
            ->assertSessionHasErrors(['invitation' => 'Workspace invitation cannot be accepted.']);
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
