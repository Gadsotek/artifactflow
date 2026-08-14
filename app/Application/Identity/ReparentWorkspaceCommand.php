<?php

declare(strict_types=1);

namespace App\Application\Identity;

final readonly class ReparentWorkspaceCommand
{
    public function __construct(
        public string $workspaceUid,
        public ?string $newParentWorkspaceUid,
        public bool $confirmed = false,
        public ?ReparentWorkspaceImpactPreview $expectedImpact = null,
    ) {
    }
}
