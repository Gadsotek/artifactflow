<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpDescriptionUpdatedPayload implements McpWirePayload
{
    public function __construct(
        public string $pageUid,
        public ?string $currentVersionUid,
        public int $metadataRevision,
        public ?McpUntrustedText $description,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $payload = [
            'page_uid' => $this->pageUid,
            'current_version_uid' => $this->currentVersionUid,
            'metadata_revision' => $this->metadataRevision,
        ];

        if ($this->description !== null) {
            $payload['description'] = $this->description->toWire();
        }

        return $payload;
    }
}
