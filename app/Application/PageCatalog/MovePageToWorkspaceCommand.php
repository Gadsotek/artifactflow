<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class MovePageToWorkspaceCommand
{
    public function __construct(
        public string $pageUid,
        public string $targetWorkspaceUid,
        public string $targetOwnerUserUid,
        public bool $confirmed,
    ) {
    }
}
