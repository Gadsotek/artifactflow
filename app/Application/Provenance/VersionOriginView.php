<?php

declare(strict_types=1);

namespace App\Application\Provenance;

final readonly class VersionOriginView
{
    /**
     * @param list<ProducerAssertionView> $producers
     */
    public function __construct(
        public string $versionUid,
        public int $versionNumber,
        public string $contentHash,
        public array $producers,
    ) {
    }
}
