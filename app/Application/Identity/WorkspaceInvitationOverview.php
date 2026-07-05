<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;

final class WorkspaceInvitationOverview
{
    public function __construct(
        private readonly WorkspaceInvitationAccess $access,
    ) {
    }

    /**
     * @return list<WorkspaceInvitationItem>
     */
    public function pendingForUser(User $user): array
    {
        $invitations = $this->activeInvitations()
            ->where('invited_email', strtolower(trim($user->email)))
            ->orderByDesc('created_at')
            ->get();

        return $this->itemsFor(array_values($invitations->all()));
    }

    /**
     * @return list<WorkspaceInvitationItem>
     */
    public function pendingForWorkspaceAdmin(User $user, ?string $workspaceUid): array
    {
        if ($workspaceUid === null || !$this->canInviteToWorkspace($user, $workspaceUid)) {
            return [];
        }

        $workspace = Workspace::query()->find($workspaceUid);

        if (!$workspace instanceof Workspace) {
            return [];
        }

        $query = $this->activeInvitations()
            ->where('workspace_uid', $workspaceUid);

        if ($this->access->role($user, $workspace) === WorkspaceRole::Editor) {
            $query->where('invited_by_user_uid', $user->uid);
        }

        $invitations = $query->orderByDesc('created_at')->get();

        return $this->itemsFor(array_values($invitations->all()));
    }

    public function canInviteToWorkspace(User $user, ?string $workspaceUid): bool
    {
        if ($workspaceUid === null) {
            return false;
        }

        $workspace = Workspace::query()->find($workspaceUid);

        if (!$workspace instanceof Workspace || $workspace->type !== WorkspaceType::Shared) {
            return false;
        }

        return $this->access->canInvite($user, $workspace);
    }

    /**
     * @return list<WorkspaceRole>
     */
    public function allowedInvitationRoles(User $user, ?string $workspaceUid): array
    {
        if ($workspaceUid === null) {
            return [];
        }

        $workspace = Workspace::query()->find($workspaceUid);

        return $workspace instanceof Workspace
            ? $this->access->allowedInvitationRoles($user, $workspace)
            : [];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<WorkspaceInvitation>
     */
    private function activeInvitations(): \Illuminate\Database\Eloquent\Builder
    {
        return WorkspaceInvitation::query()
            ->pending();
    }

    /**
     * @param list<WorkspaceInvitation> $invitations
     *
     * @return list<WorkspaceInvitationItem>
     */
    private function itemsFor(array $invitations): array
    {
        $items = [];

        // Resolve every invitation's workspace in one query rather than a find()
        // per pending invitation.
        $workspaceUids = array_map(
            static fn (WorkspaceInvitation $invitation): string => $invitation->workspace_uid,
            $invitations,
        );
        $workspaces = Workspace::query()
            ->whereIn('uid', $workspaceUids)
            ->get()
            ->keyBy('uid');

        foreach ($invitations as $invitation) {
            $workspace = $workspaces->get($invitation->workspace_uid);

            if (!$workspace instanceof Workspace) {
                continue;
            }

            $items[] = new WorkspaceInvitationItem(
                uid: $invitation->uid,
                workspaceUid: $workspace->uid,
                workspaceName: $workspace->name,
                invitedEmail: $invitation->invited_email,
                role: $invitation->role,
            );
        }

        return $items;
    }
}
