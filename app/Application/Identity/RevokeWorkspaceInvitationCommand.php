<?php

declare(strict_types=1);

namespace App\Application\Identity;

final readonly class RevokeWorkspaceInvitationCommand
{
    public function __construct(
        public string $workspaceUid,
        public string $invitationUid,
    ) {
    }
}
