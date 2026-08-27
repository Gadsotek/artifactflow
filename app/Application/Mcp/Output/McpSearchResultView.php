<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpSearchResultView implements McpWirePayload
{
    /**
     * @param list<McpUntrustedText> $tags
     */
    public function __construct(
        public string $uid,
        public McpUntrustedText $title,
        public string $type,
        public string $status,
        public ?string $currentVersionUid,
        public int $metadataRevision,
        public array $tags,
        public McpHierarchyView $hierarchy,
        public ?string $updatedAt,
        public ?McpUntrustedText $snippet,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $payload = [
            'uid' => $this->uid,
            'title' => $this->title->toWire(),
            'type' => $this->type,
            'status' => $this->status,
            'current_version_uid' => $this->currentVersionUid,
            'metadata_revision' => $this->metadataRevision,
            'tags' => array_map(
                static fn (McpUntrustedText $tag): array => $tag->toWire(),
                $this->tags,
            ),
            'hierarchy' => $this->hierarchy->toWire(),
            'updated_at' => $this->updatedAt,
        ];

        if ($this->snippet !== null) {
            $payload['snippet'] = $this->snippet->toWire();
        }

        return $payload;
    }
}
