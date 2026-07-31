<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Models\ExternalShare;

final readonly class IssuedExternalShare
{
    public function __construct(
        public ExternalShare $share,
        #[\SensitiveParameter]
        private string $rawSecret,
    ) {
    }

    public function secret(): string
    {
        return $this->rawSecret;
    }
}
