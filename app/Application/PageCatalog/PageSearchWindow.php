<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class PageSearchWindow
{
    /** @param list<PageSearchResult> $results */
    public function __construct(public array $results, public bool $hasNext)
    {
    }
}
