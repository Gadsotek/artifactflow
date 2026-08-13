<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Models\Workspace;

final readonly class ReparentWorkspaceResult
{
    /**
     * @param list<string> $reducedUserUids
     * @param list<string> $affectedPageUids
     */
    public function __construct(
        public Workspace $workspace,
        public array $reducedUserUids,
        public array $affectedPageUids,
    ) {
    }
}
