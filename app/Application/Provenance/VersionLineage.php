<?php

declare(strict_types=1);

namespace App\Application\Provenance;

final readonly class VersionLineage
{
    public function __construct(
        public string $sourceVersionUid,
        public string $sourceContentHash,
    ) {
    }
}
