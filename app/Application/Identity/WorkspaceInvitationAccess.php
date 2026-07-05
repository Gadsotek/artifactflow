<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class WorkspaceInvitationAccess
{
    public function __construct(
        private WorkspaceAccess $workspaceAccess,
    ) {
    }

    public function canInvite(User $actor, Workspace $workspace): bool
    {
        if ($workspace->type !== WorkspaceType::Shared) {
            return false;
        }

        $role = $this->role($actor, $workspace);

        return $role === WorkspaceRole::Admin
            || ($role === WorkspaceRole::Editor && $workspace->allow_editor_invites);
    }

    /**
     * @return list<WorkspaceRole>
     */
    public function allowedInvitationRoles(User $actor, Workspace $workspace): array
    {
        $role = $this->role($actor, $workspace);

        if ($role === WorkspaceRole::Admin) {
            return WorkspaceRole::cases();
        }

        if ($role === WorkspaceRole::Editor && $workspace->allow_editor_invites) {
            return [WorkspaceRole::Reader, WorkspaceRole::Editor];
        }

        return [];
    }

    /**
     * @throws AuthorizationException
     */
    public function ensureCanInvite(User $actor, Workspace $workspace, WorkspaceRole $invitedRole): void
    {
        $actorRole = $this->role($actor, $workspace);

        if ($actorRole === WorkspaceRole::Admin) {
            return;
        }

        if ($actorRole === WorkspaceRole::Editor && $workspace->allow_editor_invites) {
            if ($invitedRole === WorkspaceRole::Admin) {
                throw new AuthorizationException('Editors cannot invite workspace admins.');
            }

            return;
        }

        throw new AuthorizationException('Only workspace admins can invite members.');
    }

    /**
     * @throws AuthorizationException
     */
    public function ensureCanRevoke(
        User $actor,
        Workspace $workspace,
        WorkspaceInvitation $invitation,
    ): void {
        $actorRole = $this->role($actor, $workspace);

        if ($actorRole === WorkspaceRole::Admin) {
            return;
        }

        if ($actorRole === WorkspaceRole::Editor && $workspace->allow_editor_invites) {
            if ($invitation->invited_by_user_uid !== $actor->uid) {
                throw new AuthorizationException('Editors can revoke only invitations they created.');
            }

            return;
        }

        throw new AuthorizationException('Only workspace admins can revoke invitations.');
    }

    public function role(User $actor, Workspace $workspace): ?WorkspaceRole
    {
        return $this->workspaceAccess->role($actor->uid, $workspace->uid);
    }
}
