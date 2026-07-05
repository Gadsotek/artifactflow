<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\WorkspaceRole;

final readonly class InviteUserToWorkspaceCommand
{
    public function __construct(
        public string $workspaceUid,
        public string $email,
        public WorkspaceRole $role,
    ) {
    }
}
