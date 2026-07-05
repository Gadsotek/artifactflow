<?php

declare(strict_types=1);

namespace App\Application\Identity;

final readonly class RegisterWorkspaceInvitationUserCommand
{
    public function __construct(
        public string $invitationUid,
        public string $presentedToken,
        public string $name,
        public string $password,
    ) {
    }
}
