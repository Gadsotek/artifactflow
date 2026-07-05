<?php

declare(strict_types=1);

namespace App\Application\Identity;

final readonly class RemoveWorkspaceMemberCommand
{
    public function __construct(
        public string $workspaceUid,
        public string $membershipUid,
        public ?string $replacementOwnerUserUid,
    ) {
    }
}
