<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Output\McpStoredProvenanceReceipt;
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

    public function forVersion(PageVersion $version): McpStoredProvenanceReceipt
    {
        $view = $this->provenance->forVersion($version);
        return new McpStoredProvenanceReceipt(
            supplied: $view->versionIngest instanceof VersionIngestView
                && $view->versionIngest->provenanceSuppliedAtIngest,
            completeness: $view->completeness->value,
            directVersionProducers: array_map($this->payload->producer(...), $view->directVersionProducers),
        );
    }
}
