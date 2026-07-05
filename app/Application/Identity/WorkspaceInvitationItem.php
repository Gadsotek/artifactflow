<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\WorkspaceRole;

final readonly class WorkspaceInvitationItem
{
    public function __construct(
        public string $uid,
        public string $workspaceUid,
        public string $workspaceName,
        public string $invitedEmail,
        public WorkspaceRole $role,
    ) {
    }
}
