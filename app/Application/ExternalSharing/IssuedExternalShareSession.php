<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Models\ExternalShareSession;

final readonly class IssuedExternalShareSession
{
    public function __construct(
        public ExternalShareSession $session,
        #[\SensitiveParameter]
        private string $credential,
    ) {
    }

    public function credential(): string
    {
        return $this->credential;
    }
}
