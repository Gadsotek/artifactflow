<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

final readonly class GeneratedExternalShareSecret
{
    public function __construct(
        #[\SensitiveParameter]
        private string $secret,
        public string $hash,
    ) {
    }

    public function reveal(): string
    {
        return $this->secret;
    }
}
