<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

final readonly class ExternalShareInventoryPage
{
    /**
     * @param list<ExternalShareInventoryItem> $items
     */
    public function __construct(
        public array $items,
        public bool $hasMore,
        public int $activeCount = 0,
    ) {
    }
}
