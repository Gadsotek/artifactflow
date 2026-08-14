<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\WorkspaceRole;

final readonly class EffectiveWorkspaceMembershipOrigin
{
    public function __construct(
        public string $membershipUid,
        public string $workspaceUid,
        public WorkspaceRole $role,
        public int $depth,
        public bool $isInherited,
    ) {
    }
}
