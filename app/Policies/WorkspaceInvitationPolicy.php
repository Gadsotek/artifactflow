<?php

declare(strict_types=1);

namespace App\Policies;

use App\Application\Identity\WorkspaceInvitationAccess;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;

final readonly class WorkspaceInvitationPolicy
{
    public function __construct(
        private WorkspaceInvitationAccess $access,
    ) {
    }

    public function revoke(User $user, WorkspaceInvitation $invitation, Workspace $workspace): bool|Response
    {
        if ($invitation->workspace_uid !== $workspace->uid) {
            return Response::denyAsNotFound();
        }

        try {
            $this->access->ensureCanRevoke($user, $workspace, $invitation);
        } catch (AuthorizationException) {
            return false;
        }

        return true;
    }

    public function accept(User $user, WorkspaceInvitation $invitation): bool
    {
        return $invitation->isPending()
            && hash_equals($invitation->invited_email, strtolower(trim($user->email)));
    }
}
