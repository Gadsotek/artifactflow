<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class PageAccessWorkspaceTargetItem
{
    public function __construct(
        public string $uid,
        public string $name,
    ) {
    }
}
