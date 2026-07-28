<?php

declare(strict_types=1);

namespace App\Application\Provenance;

use App\Domain\Provenance\ProvenanceCompleteness;
use App\Domain\Provenance\ProvenanceEvidenceType;

final readonly class PageVersionProvenanceView
{
    /**
     * @param list<ProducerAssertionView> $pageOriginProducers
     * @param list<ProducerAssertionView> $directVersionProducers
     */
    public function __construct(
        public ProvenanceCompleteness $completeness,
        public ?ProvenanceEvidenceType $strongestEvidence,
        public ?VersionIngestView $versionIngest,
        public array $pageOriginProducers,
        public array $directVersionProducers,
        public ?VersionOriginView $effectiveContentOrigin,
    ) {
    }

    /**
     * The current version's declarations take precedence. A restore without a
     * new declaration inherits the producers attached to the byte-equivalent
     * source version rather than manufacturing another producer assertion.
     *
     * @return list<ProducerAssertionView>
     */
    public function contentOriginProducers(): array
    {
        if ($this->directVersionProducers !== []) {
            return $this->directVersionProducers;
        }

        return $this->effectiveContentOrigin instanceof VersionOriginView
            ? $this->effectiveContentOrigin->producers
            : [];
    }

    public function inheritsContentOrigin(): bool
    {
        return $this->directVersionProducers === []
            && $this->effectiveContentOrigin instanceof VersionOriginView
            && $this->effectiveContentOrigin->producers !== []
            && $this->versionIngest?->pageVersionUid !== $this->effectiveContentOrigin->versionUid;
    }
}
