<?php

declare(strict_types=1);

namespace App\Application\Identity;

final readonly class ExcludeInheritedWorkspaceMemberCommand
{
    public function __construct(
        public string $workspaceUid,
        public string $memberUserUid,
        public ?string $replacementOwnerUserUid,
    ) {
    }
}
