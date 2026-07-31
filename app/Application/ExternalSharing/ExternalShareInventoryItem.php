<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Domain\ExternalSharing\ExternalShareMode;
use App\Domain\ExternalSharing\ExternalShareStatus;
use Carbon\CarbonImmutable;

final readonly class ExternalShareInventoryItem
{
    public function __construct(
        public string $uid,
        public ExternalShareMode $mode,
        public ExternalShareStatus $status,
        public ?CarbonImmutable $expiresAt,
        public string $createdByUserUid,
        public string $createdByName,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $redeemedAt,
        public ?CarbonImmutable $revokedAt,
        public ?string $revokedByUserUid,
        public ?string $revokedByName,
        public ?CarbonImmutable $firstViewedAt,
        public ?CarbonImmutable $lastViewedAt,
        public int $viewSessionCount,
    ) {
    }
}
