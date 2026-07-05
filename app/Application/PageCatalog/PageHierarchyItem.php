<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PageStatus;

final readonly class PageHierarchyItem
{
    public function __construct(
        public string $pageUid,
        public string $title,
        public PageStatus $status,
    ) {
    }
}
