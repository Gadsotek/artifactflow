<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

final readonly class PruneExternalShareResult
{
    public function __construct(
        public int $sessionCount,
        public int $shareCount,
    ) {
    }
}
