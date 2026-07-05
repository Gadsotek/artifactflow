<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class PageVisibilityScope
{
    /**
     * @param list<string> $membershipWorkspaceUids
     */
    public function __construct(
        public array $membershipWorkspaceUids,
    ) {
    }
}
