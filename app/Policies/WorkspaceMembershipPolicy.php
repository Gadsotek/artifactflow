<?php

declare(strict_types=1);

namespace App\Policies;

use App\Application\PageCatalog\PageAccess;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\Response;

final readonly class WorkspaceMembershipPolicy
{
    public function __construct(
        private PageAccess $pageAccess,
    ) {
    }

    public function update(User $user, WorkspaceMembership $membership, Workspace $workspace): bool|Response
    {
        return $this->canManageMembership($user, $membership, $workspace);
    }

    public function delete(User $user, WorkspaceMembership $membership, Workspace $workspace): bool|Response
    {
        return $this->canManageMembership($user, $membership, $workspace);
    }

    private function canManageMembership(User $user, WorkspaceMembership $membership, Workspace $workspace): bool|Response
    {
        if ($membership->workspace_uid !== $workspace->uid) {
            return Response::denyAsNotFound();
        }

        if ($workspace->type !== WorkspaceType::Shared) {
            return false;
        }

        return $this->pageAccess->workspaceRole($user, $workspace->uid) === WorkspaceRole::Admin;
    }
}
