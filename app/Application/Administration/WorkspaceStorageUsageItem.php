<?php

declare(strict_types=1);

namespace App\Application\Administration;

final readonly class WorkspaceStorageUsageItem
{
    public function __construct(
        public string $uid,
        public string $name,
        public string $type,
        public int $pageCount,
        public int $versionCount,
        public int $usedBytes,
        public string $usedBytesLabel,
        public int $limitBytes,
        public string $limitBytesLabel,
        public string $percentUsedLabel,
        public string $progressPercent,
        public string $ariaPercent,
    ) {
    }

    public function usageLabel(): string
    {
        return sprintf('%s of %s', $this->usedBytesLabel, $this->limitBytesLabel);
    }
}
