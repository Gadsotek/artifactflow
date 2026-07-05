<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Domain\Identity\WorkspaceRole;
use App\Domain\Identity\WorkspaceType;

final readonly class WorkspaceNavigationItem
{
    public function __construct(
        public string $uid,
        public string $name,
        public WorkspaceType $type,
        public WorkspaceRole $role,
        public bool $isMembership = true,
        public ?string $accessLabel = null,
    ) {
    }
}
