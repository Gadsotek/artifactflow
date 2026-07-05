<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\PageVersion;

final readonly class RevertToPreviousVersionResult
{
    public function __construct(
        public PageVersion $restoredVersion,
        public PageVersion $restoredFromVersion,
    ) {
    }
}
