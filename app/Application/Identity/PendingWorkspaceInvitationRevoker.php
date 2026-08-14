<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Models\User;
use App\Models\WorkspaceInvitation;

final readonly class PendingWorkspaceInvitationRevoker
{
    /**
     * @param list<string> $workspaceUids
     */
    public function forUserAcrossWorkspaces(string $userUid, array $workspaceUids): int
    {
        $workspaceUids = array_values(array_unique($workspaceUids));

        if ($workspaceUids === []) {
            return 0;
        }

        $user = User::query()->find($userUid);

        if (!$user instanceof User) {
            return 0;
        }

        $invitations = WorkspaceInvitation::query()
            ->whereIn('workspace_uid', $workspaceUids)
            ->where('invited_email', strtolower(trim($user->email)))
            // Accepted and expired rows are intentionally revoked too: the
            // invitation lifecycle can reactivate an inactive row with a new
            // token, so revocation preserves the removal boundary across reissue.
            ->whereNull('revoked_at')
            ->orderBy('uid')
            ->lockForUpdate()
            ->get();

        foreach ($invitations as $invitation) {
            $invitation->forceFill(['revoked_at' => now()])->save();
        }

        return $invitations->count();
    }
}
