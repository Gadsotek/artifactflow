<?php

declare(strict_types=1);

namespace App\Application\Provenance;

use App\Domain\Provenance\ProducerIdentityPrecision;
use App\Domain\Provenance\ProducerKind;
use App\Domain\Provenance\ProvenanceEvidenceType;
use Carbon\CarbonImmutable;

final readonly class ProducerAssertionView
{
    /**
     * @param list<ExternalOriginReferenceView> $references
     * @param list<ProducerClaimExtension> $claimExtensions
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
        public ?string $reportedProvider = null,
        public array $claimExtensions = [],
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
            ProducerKind::Ai => $this->modelLabel
                ?? $this->modelId
                ?? $this->reportedProvider
                ?? $this->providerKey
                ?? 'Unknown AI producer',
            ProducerKind::Software => $this->producerName ?? 'Unknown software producer',
            ProducerKind::Human => $this->producerName ?? 'Anonymous human',
        };
    }

    public function identityPrecision(): ProducerIdentityPrecision
    {
        if ($this->kind !== ProducerKind::Ai) {
            return $this->producerName !== null
                ? ProducerIdentityPrecision::Named
                : ProducerIdentityPrecision::Unspecified;
        }

        if ($this->providerKey !== null && $this->modelId !== null) {
            return ProducerIdentityPrecision::ExactModel;
        }

        if ($this->modelId !== null) {
            return ProducerIdentityPrecision::ModelIdOnly;
        }

        if ($this->modelLabel !== null) {
            return ProducerIdentityPrecision::ModelLabel;
        }

        return $this->providerKey !== null
            ? ProducerIdentityPrecision::ProviderOnly
            : ProducerIdentityPrecision::Unspecified;
    }
}
