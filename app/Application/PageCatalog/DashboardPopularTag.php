<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class DashboardPopularTag
{
    public function __construct(
        public string $name,
        public int $pageCount,
    ) {
    }
}
