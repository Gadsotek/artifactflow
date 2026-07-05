<?php

declare(strict_types=1);

namespace App\Application\Identity;

final readonly class WorkspaceOwnershipCandidate
{
    public function __construct(
        public string $userUid,
        public string $name,
    ) {
    }
}
