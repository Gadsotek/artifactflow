<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\ChangeWorkspaceMembershipRole;
use App\Application\Identity\ChangeWorkspaceMembershipRoleCommand;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\RemoveWorkspaceMember;
use App\Application\Identity\RemoveWorkspaceMemberCommand;
use App\Application\Identity\UpdateWorkspaceSettings;
use App\Application\Identity\UpdateWorkspaceSettingsCommand;
use App\Application\Identity\WorkspaceInvitationAccess;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpRequestContext;
use App\Domain\Identity\WorkspaceRole;
use App\Models\McpAccessToken;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Security boundary: while an MCP access token drives the request, effective
 * authority de-elevates workspace admins to editors and disables admin-class
 * capabilities. The Identity workspace-admin checks must apply the same
 * de-elevation as PageAccess::workspaceRole(), so an MCP-authority-constrained
 * context can never perform workspace-admin actions even for an actor whose
 * raw membership row says admin. No MCP tool reaches these handlers today;
 * this guards the boundary at the application-service level.
 */
final class WorkspaceAdminMcpDeElevationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mcp_constrained_admin_cannot_update_workspace_settings(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $this->activateMcpAuthority($admin);

        try {
            app(UpdateWorkspaceSettings::class)->handle($admin, new UpdateWorkspaceSettingsCommand(
                workspaceUid: $workspace->uid,
                name: 'Renamed Through Mcp',
                allowEditorInvites: true,
                allowEditorPageSharing: true,
            ));
            $this->fail('Expected MCP-constrained workspace settings update to be forbidden.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Only workspace admins can update workspace settings.', $exception->getMessage());
        }

        $this->assertSame('Platform Team', $workspace->refresh()->name);
    }

    public function test_mcp_constrained_admin_cannot_change_member_roles(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $member = $this->createUser('Member User', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $membership = $this->addMember($workspace->uid, $member, WorkspaceRole::Reader);
        $this->activateMcpAuthority($admin);

        try {
            app(ChangeWorkspaceMembershipRole::class)->handle($admin, new ChangeWorkspaceMembershipRoleCommand(
                workspaceUid: $workspace->uid,
                membershipUid: $membership->uid,
                role: WorkspaceRole::Admin,
            ));
            $this->fail('Expected MCP-constrained membership role change to be forbidden.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Only workspace admins can change member roles.', $exception->getMessage());
        }

        $this->assertSame(WorkspaceRole::Reader, $membership->refresh()->role);
    }

    public function test_mcp_constrained_admin_cannot_remove_members(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $member = $this->createUser('Member User', 'member@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $membership = $this->addMember($workspace->uid, $member, WorkspaceRole::Reader);
        $this->activateMcpAuthority($admin);

        try {
            app(RemoveWorkspaceMember::class)->handle($admin, new RemoveWorkspaceMemberCommand(
                workspaceUid: $workspace->uid,
                membershipUid: $membership->uid,
                replacementOwnerUserUid: null,
            ));
            $this->fail('Expected MCP-constrained member removal to be forbidden.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Only workspace admins can remove members.', $exception->getMessage());
        }

        $this->assertDatabaseHas('workspace_memberships', ['uid' => $membership->uid]);
    }

    public function test_mcp_constrained_admin_holds_no_invitation_authority(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $invitation = WorkspaceInvitation::query()->forceCreate([
            'workspace_uid' => $workspace->uid,
            'invited_email' => 'invitee@example.test',
            'role' => WorkspaceRole::Reader,
            'invited_by_user_uid' => $admin->uid,
            'expires_at' => now()->addDay(),
        ]);
        $this->activateMcpAuthority($admin);
        $access = app(WorkspaceInvitationAccess::class);

        $this->assertFalse($access->canInvite($admin, $workspace));
        $this->assertSame([], $access->allowedInvitationRoles($admin, $workspace));

        try {
            $access->ensureCanInvite($admin, $workspace, WorkspaceRole::Reader);
            $this->fail('Expected MCP-constrained invitation creation to be forbidden.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Only workspace admins can invite members.', $exception->getMessage());
        }

        try {
            $access->ensureCanRevoke($admin, $workspace, $invitation);
            $this->fail('Expected MCP-constrained invitation revocation to be forbidden.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Only workspace admins can revoke invitations.', $exception->getMessage());
        }
    }

    public function test_workspace_scope_outside_the_token_grants_no_identity_role(): void
    {
        $admin = $this->createUser('Admin User', 'admin@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'Platform Team');
        $otherWorkspace = app(CreateSharedWorkspace::class)->handle($admin, 'Other Team');
        $this->activateMcpAuthority($admin, [$otherWorkspace->uid]);

        try {
            app(UpdateWorkspaceSettings::class)->handle($admin, new UpdateWorkspaceSettingsCommand(
                workspaceUid: $workspace->uid,
                name: 'Renamed Outside Scope',
                allowEditorInvites: true,
                allowEditorPageSharing: true,
            ));
            $this->fail('Expected out-of-scope workspace settings update to be forbidden.');
        } catch (AuthorizationException $exception) {
            $this->assertSame('Only workspace admins can update workspace settings.', $exception->getMessage());
        }

        $this->assertSame('Platform Team', $workspace->refresh()->name);
    }

    /**
     * @param list<string>|null $workspaceUids
     */
    private function activateMcpAuthority(User $principal, ?array $workspaceUids = null): void
    {
        $token = McpAccessToken::query()->forceCreate([
            'principal_user_uid' => $principal->uid,
            'name' => 'Test token',
            'token_hash' => McpAccessTokenIssuer::hashToken('af_mcp_test_token'),
            'scopes' => McpAccessTokenIssuer::allowedScopes(),
            'workspace_uids' => $workspaceUids,
            'expires_at' => now()->addHour(),
        ]);

        app(McpRequestContext::class)->activate($token, 'test-session');
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
}
