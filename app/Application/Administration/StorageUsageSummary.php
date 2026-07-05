<?php

declare(strict_types=1);

namespace App\Application\Administration;

final readonly class StorageUsageSummary
{
    public function __construct(
        public int $workspaceCount,
        public int $pageCount,
        public int $versionCount,
        public int $usedBytes,
        public string $usedBytesLabel,
    ) {
    }
}
