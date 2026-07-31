<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Domain\ExternalSharing\ExternalShareMode;
use Carbon\CarbonImmutable;

final readonly class CreateExternalShareCommand
{
    public function __construct(
        public string $pageUid,
        public ExternalShareMode $mode,
        public ?CarbonImmutable $expiresAt,
    ) {
    }
}
