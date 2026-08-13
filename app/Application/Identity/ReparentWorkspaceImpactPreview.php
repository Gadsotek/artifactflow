<?php

declare(strict_types=1);

namespace App\Application\Identity;

final readonly class ReparentWorkspaceImpactPreview
{
    public function __construct(
        public string $workspaceUid,
        public ?string $newParentWorkspaceUid,
        public int $movedWorkspaceCount,
        public int $affectedPageCount,
        public int $gainedUserCount,
        public int $reducedUserCount,
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->workspaceUid === $other->workspaceUid
            && $this->newParentWorkspaceUid === $other->newParentWorkspaceUid
            && $this->movedWorkspaceCount === $other->movedWorkspaceCount
            && $this->affectedPageCount === $other->affectedPageCount
            && $this->gainedUserCount === $other->gainedUserCount
            && $this->reducedUserCount === $other->reducedUserCount;
    }
}
