<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

final readonly class GeneratedExternalShareSessionCredential
{
    public function __construct(
        #[\SensitiveParameter]
        private string $credential,
        public string $hash,
    ) {
    }

    public function reveal(): string
    {
        return $this->credential;
    }
}
