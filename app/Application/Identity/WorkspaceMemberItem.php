<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\WorkspaceRole;

final readonly class WorkspaceMemberItem
{
    public function __construct(
        public string $membershipUid,
        public string $userUid,
        public string $name,
        public string $email,
        public WorkspaceRole $role,
        public bool $isCurrentUser,
        public int $ownedPageCount,
    ) {
    }

    public function canOwnPages(): bool
    {
        return $this->role === WorkspaceRole::Editor || $this->role === WorkspaceRole::Admin;
    }
}
