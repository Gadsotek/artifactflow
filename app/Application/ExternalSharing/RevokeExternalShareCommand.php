<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

final readonly class RevokeExternalShareCommand
{
    public function __construct(
        public string $pageUid,
        public string $externalShareUid,
    ) {
    }
}
