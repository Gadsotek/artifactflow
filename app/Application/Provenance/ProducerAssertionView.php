<?php

declare(strict_types=1);

namespace App\Application\Provenance;

use App\Domain\Provenance\ProducerKind;
use App\Domain\Provenance\ProvenanceEvidenceType;
use Carbon\CarbonImmutable;

final readonly class ProducerAssertionView
{
    /**
     * @param list<ExternalOriginReferenceView> $references
     */
    public function __construct(
        public string $uid,
        public ProducerKind $kind,
        public ?string $producerName,
        public ?string $producerVersion,
        public ?string $providerKey,
        public ?string $modelId,
        public ?string $modelLabel,
        public ?string $modelVersion,
        public ?CarbonImmutable $generatedAt,
        public ProvenanceEvidenceType $evidenceType,
        public array $references,
    ) {
    }

    public function isIdentified(): bool
    {
        return match ($this->kind) {
            ProducerKind::Ai => $this->providerKey !== null && $this->modelId !== null,
            ProducerKind::Human, ProducerKind::Software => $this->producerName !== null,
        };
    }

    public function displayName(): string
    {
        return match ($this->kind) {
            ProducerKind::Ai => $this->modelLabel ?? $this->modelId ?? 'Unknown AI producer',
            ProducerKind::Software => $this->producerName ?? 'Unknown software producer',
            ProducerKind::Human => $this->producerName ?? 'Anonymous human',
        };
    }
}
