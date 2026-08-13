<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\WorkspaceRole;

final readonly class EffectiveWorkspaceMembership
{
    /**
     * @param list<EffectiveWorkspaceMembershipOrigin> $origins
     */
    public function __construct(
        public string $workspaceUid,
        public ?WorkspaceRole $role,
        public array $origins,
    ) {
    }

    public function isInherited(): bool
    {
        if (!$this->role instanceof WorkspaceRole || $this->origins === []) {
            return false;
        }

        foreach ($this->origins as $origin) {
            if (!$origin->isInherited) {
                return false;
            }
        }

        return true;
    }
}
