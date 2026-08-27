<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpReadPayload implements McpWirePayload
{
    public function __construct(
        public McpPageView $page,
        public string $currentVersionUid,
        public ?McpUntrustedText $currentVersionChangeSummary,
        public McpHierarchyView $hierarchy,
        public ?McpProvenanceView $provenance = null,
        public McpUntrustedText|McpUntrustedImage|null $content = null,
        public ?McpUntrustedText $extractedText = null,
        public ?McpImageSearchabilityView $imageSearchability = null,
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

        if ($this->currentVersionChangeSummary !== null) {
            $payload['current_version_change_summary'] = $this->currentVersionChangeSummary->toWire();
        }

        $payload['hierarchy'] = $this->hierarchy->toWire();

        if ($this->provenance !== null) {
            $payload['provenance'] = $this->provenance->toWire();
        }

        if ($this->content !== null) {
            $payload['content'] = $this->content->toWire();
        }

        if ($this->extractedText !== null) {
            $payload['extracted_text'] = $this->extractedText->toWire();
        }

        if ($this->imageSearchability !== null) {
            $payload['image_searchability'] = $this->imageSearchability->toWire();
        }

        if ($this->pdf !== null) {
            $payload['pdf'] = $this->pdf->toWire();
        }

        return $payload;
    }
}
