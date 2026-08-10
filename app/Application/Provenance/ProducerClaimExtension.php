<?php

declare(strict_types=1);

namespace App\Application\Provenance;

final readonly class ProducerClaimExtension
{
    public function __construct(
        public string $key,
        public string $value,
    ) {
    }
}
