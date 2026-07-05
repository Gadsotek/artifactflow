<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\WorkspaceRole;

final readonly class AddWorkspaceCollaboratorCommand
{
    public function __construct(
        public string $workspaceUid,
        public string $userUid,
        public WorkspaceRole $role,
    ) {
    }
}
