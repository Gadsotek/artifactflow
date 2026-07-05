<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class RecountWorkspaceStorageResult
{
    public function __construct(
        public int $workspacesChecked,
        public int $workspacesCorrected,
    ) {
    }
}
