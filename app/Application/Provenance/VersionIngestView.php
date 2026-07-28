<?php

declare(strict_types=1);

namespace App\Application\Provenance;

use App\Domain\PageCatalog\PageVersionSource;
use App\Domain\Provenance\VersionOperation;
use Carbon\CarbonImmutable;

final readonly class VersionIngestView
{
    public function __construct(
        public string $uid,
        public string $pageVersionUid,
        public int $versionNumber,
        public string $contentHash,
        public VersionOperation $operation,
        public PageVersionSource $ingestMethod,
        public string $actorUserUid,
        public string $actorName,
        public ?string $mcpAccessTokenUid,
        public ?string $mcpTransportSessionId,
        public ?string $mcpReportedClientName,
        public ?string $mcpReportedClientVersion,
        public bool $provenanceSuppliedAtIngest,
        public ?string $derivedFromVersionUid,
        public ?string $contentEquivalentToVersionUid,
        public string $contentOriginVersionUid,
        public CarbonImmutable $recordedAt,
    ) {
    }
}
