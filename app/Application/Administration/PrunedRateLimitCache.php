<?php

declare(strict_types=1);

namespace App\Application\Administration;

final readonly class PrunedRateLimitCache
{
    public function __construct(
        public int $entryCount,
        public int $lockCount,
    ) {
    }
}
