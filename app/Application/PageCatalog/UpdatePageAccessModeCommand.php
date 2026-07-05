<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PageAccessMode;

final readonly class UpdatePageAccessModeCommand
{
    public function __construct(
        public string $pageUid,
        public PageAccessMode $accessMode,
    ) {
    }
}
