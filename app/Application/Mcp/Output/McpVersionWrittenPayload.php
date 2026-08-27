<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpVersionWrittenPayload implements McpWirePayload
{
    public function __construct(
        public string $pageUid,
        public string $versionUid,
        public string $currentVersionUid,
        public McpStoredProvenanceReceipt $storedProvenance,
        public ?McpPdfFactsView $pdf = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $payload = [
            'page_uid' => $this->pageUid,
            'version_uid' => $this->versionUid,
            'current_version_uid' => $this->currentVersionUid,
        ];

        if ($this->pdf !== null) {
            $payload['pdf'] = $this->pdf->toWire();
        }

        $payload['stored_provenance'] = $this->storedProvenance->toWire();

        return $payload;
    }
}
