<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\Page;

final readonly class PageSearchResult
{
    public function __construct(
        public Page $page,
        public ?string $snippet,
        public float $rank,
        public ?string $workspaceName,
    ) {
    }
}
