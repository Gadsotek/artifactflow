<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use Carbon\CarbonImmutable;

final readonly class PageActivityItem
{
    public function __construct(
        public string $summary,
        public string $actorName,
        public CarbonImmutable $occurredAt,
    ) {
    }
}
