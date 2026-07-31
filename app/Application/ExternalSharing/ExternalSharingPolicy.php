<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

final readonly class ExternalSharingPolicy
{
    public function __construct(
        public bool $enabled,
        public bool $acknowledgementRequired,
        public int $maxExpiryHours,
    ) {
    }
}
