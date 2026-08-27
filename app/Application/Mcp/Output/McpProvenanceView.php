<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpProvenanceView implements McpWirePayload
{
    /**
     * @param list<McpProducerView> $producers
     * @param list<string> $pageOriginProducerUids
     * @param list<string> $directVersionProducerUids
     */
    public function __construct(
        public string $completeness,
        public string $strongestEvidence,
        public ?McpVersionIngestPayload $versionIngest,
        public array $producers,
        public array $pageOriginProducerUids,
        public array $directVersionProducerUids,
        public ?McpVersionOriginPayload $effectiveContentOrigin,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $payload = [
            'provenance_completeness' => $this->completeness,
            'strongest_evidence' => $this->strongestEvidence,
        ];

        if ($this->versionIngest !== null) {
            $payload['version_ingest'] = $this->versionIngest->toWire();
        }

        $payload['producers'] = array_map(
            static fn (McpProducerView $producer): array => $producer->toWire(),
            $this->producers,
        );
        $payload['page_origin_producer_uids'] = $this->pageOriginProducerUids;
        $payload['direct_version_producer_uids'] = $this->directVersionProducerUids;

        if ($this->effectiveContentOrigin !== null) {
            $payload['effective_content_origin'] = $this->effectiveContentOrigin->toWire();
        }

        return $payload;
    }
}
