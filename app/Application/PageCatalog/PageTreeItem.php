<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class PageTreeItem
{
    public int $visualDepth;

    public function __construct(
        public PageSearchResult $result,
        public int $depth,
        public ?string $parentTitle,
    ) {
        $this->visualDepth = min($depth, 4);
    }
}
