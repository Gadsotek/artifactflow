<?php

declare(strict_types=1);

namespace App\Application\Diagnostics;

final readonly class ProcessorHealthTarget
{
    public function __construct(
        public string $origin,
        public ?string $socketPath,
        public string $sharedSecret,
        public int $connectTimeoutSeconds,
        public int $timeoutSeconds,
    ) {
    }
}
