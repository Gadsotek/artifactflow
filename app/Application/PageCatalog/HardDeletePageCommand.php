<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class HardDeletePageCommand
{
    public function __construct(
        public string $pageUid,
        public string $confirmation,
    ) {
    }
}
