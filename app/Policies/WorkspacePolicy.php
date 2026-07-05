<?php

declare(strict_types=1);

namespace App\Policies;

use App\Application\Identity\WorkspaceInvitationAccess;
use App\Application\PageCatalog\PageAccess;
use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;
use App\Models\User;
use App\Models\Workspace;

final readonly class WorkspacePolicy
{
    public function __construct(
        private WorkspaceInvitationAccess $invitations,
        private PageAccess $pageAccess,
    ) {
    }

    public function manage(User $user, Workspace $workspace): bool
    {
        return $workspace->type === WorkspaceType::Shared
            && $this->role($user, $workspace) === WorkspaceRole::Admin;
    }

    public function invite(User $user, Workspace $workspace): bool
    {
        return $this->invitations->canInvite($user, $workspace);
    }

    public function createCategory(User $user, Workspace $workspace): bool
    {
        return $this->pageAccess->canCreateInWorkspace($user, $workspace->uid);
    }

    public function switch(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace) !== null;
    }

    private function role(User $user, Workspace $workspace): ?WorkspaceRole
    {
        return $this->pageAccess->workspaceRole($user, $workspace->uid);
    }
}
