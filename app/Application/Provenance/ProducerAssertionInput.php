<?php

declare(strict_types=1);

namespace App\Application\Provenance;

use App\Domain\Provenance\ProducerKind;
use Carbon\CarbonImmutable;

final readonly class ProducerAssertionInput
{
    /**
     * @param list<ExternalOriginReferenceInput> $references
     * @param list<ProducerClaimExtension> $claimExtensions
     */
    public function __construct(
        public ProducerKind $kind,
        public ?string $producerName,
        public ?string $producerVersion,
        public ?string $providerKey,
        public ?string $modelId,
        public ?string $modelLabel,
        public ?string $modelVersion,
        public ?CarbonImmutable $generatedAt,
        public array $references,
        public ?string $reportedProvider = null,
        public array $claimExtensions = [],
    ) {
    }
}
