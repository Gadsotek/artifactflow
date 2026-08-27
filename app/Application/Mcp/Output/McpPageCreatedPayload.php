<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpPageCreatedPayload implements McpWirePayload
{
    public function __construct(
        public McpPageView $page,
        public ?string $currentVersionUid,
        public McpStoredProvenanceReceipt $storedProvenance,
        public ?McpPdfFactsView $pdf = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $payload = $this->page->toWire();
        $payload['current_version_uid'] = $this->currentVersionUid;

        if ($this->pdf !== null) {
            $payload['pdf'] = $this->pdf->toWire();
        }

        $payload['stored_provenance'] = $this->storedProvenance->toWire();

        return $payload;
    }
}
