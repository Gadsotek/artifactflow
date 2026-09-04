<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpRevertedPayload implements McpWirePayload
{
    public function __construct(
        public string $pageUid,
        public string $versionUid,
        public string $currentVersionUid,
        public string $restoredFromVersionUid,
        public ?McpPdfFactsView $pdf = null,
        public ?McpXlsxFactsView $xlsx = null,
        public ?McpDocxFactsView $docx = null,
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
            'restored_from_version_uid' => $this->restoredFromVersionUid,
        ];

        if ($this->pdf !== null) {
            $payload['pdf'] = $this->pdf->toWire();
        }

        if ($this->xlsx !== null) {
            $payload['xlsx'] = $this->xlsx->toWire();
        }

        if ($this->docx !== null) {
            $payload['docx'] = $this->docx->toWire();
        }

        return $payload;
    }
}
