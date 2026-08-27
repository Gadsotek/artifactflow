<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpVersionOriginPayload implements McpWirePayload
{
    /**
     * @param list<string> $producerUids
     */
    public function __construct(
        public string $pageVersionUid,
        public int $versionNumber,
        public string $contentHash,
        public array $producerUids,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'page_version_uid' => $this->pageVersionUid,
            'version_number' => $this->versionNumber,
            'content_hash' => $this->contentHash,
            'producer_uids' => $this->producerUids,
        ];
    }
}
