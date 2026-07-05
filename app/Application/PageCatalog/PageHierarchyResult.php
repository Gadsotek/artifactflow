<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class PageHierarchyResult
{
    /**
     * @param list<PageHierarchyItem> $children
     */
    public function __construct(
        public ?PageHierarchyItem $parent,
        public array $children,
    ) {
    }
}
