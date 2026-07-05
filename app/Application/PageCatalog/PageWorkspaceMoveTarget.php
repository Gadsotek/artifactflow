<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class PageWorkspaceMoveTarget
{
    /**
     * @param list<PageWorkspaceMoveOwner> $owners
     */
    public function __construct(
        public string $workspaceUid,
        public string $workspaceName,
        public array $owners,
    ) {
    }
}
