<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Provenance\ProvenanceReadModel;
use App\Application\Provenance\VersionIngestView;
use App\Models\PageVersion;

final readonly class McpStoredProvenancePayload
{
    public function __construct(
        private ProvenanceReadModel $provenance,
        private McpProvenancePayload $payload,
    ) {
    }

    /**
     * @return array{stored_provenance: array<string, mixed>}
     */
    public function forVersion(PageVersion $version): array
    {
        $view = $this->provenance->forVersion($version);
        $payload = $this->payload->make($view);

        return [
            'stored_provenance' => [
                'supplied' => $view->versionIngest instanceof VersionIngestView
                    && $view->versionIngest->provenanceSuppliedAtIngest,
                'completeness' => $view->completeness->value,
                'direct_version_producers' => $payload['direct_version_producers'],
            ],
        ];
    }
}
