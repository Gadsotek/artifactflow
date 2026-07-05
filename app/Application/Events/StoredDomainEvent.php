<?php

declare(strict_types=1);

namespace App\Application\Events;

use Carbon\CarbonImmutable;

final readonly class StoredDomainEvent
{
    /**
     * @param array<string, bool|int|string|null> $payload
     */
    public function __construct(
        public string $uid,
        public string $eventType,
        public string $aggregateType,
        public string $aggregateUid,
        public array $payload,
        public CarbonImmutable $occurredAt,
    ) {
    }
}
