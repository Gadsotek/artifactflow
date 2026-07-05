<?php

declare(strict_types=1);

namespace App\Application\Administration;

final readonly class InstallationStorageUsage
{
    /**
     * @param list<WorkspaceStorageUsageItem> $workspaces
     * @param list<PageStorageUsageItem> $pages
     */
    public function __construct(
        public StorageUsageSummary $summary,
        public array $workspaces,
        public array $pages,
    ) {
    }
}
