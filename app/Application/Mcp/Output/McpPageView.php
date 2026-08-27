<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpPageView implements McpWirePayload
{
    /**
     * @param list<McpUntrustedText> $tags
     */
    public function __construct(
        public string $uid,
        public McpUntrustedText $title,
        public ?McpUntrustedText $description,
        public string $type,
        public string $status,
        public int $metadataRevision,
        public array $tags,
        public ?string $updatedAt,
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
        ];

        if ($this->description !== null) {
            $payload['description'] = $this->description->toWire();
        }

        $payload['type'] = $this->type;
        $payload['status'] = $this->status;
        $payload['metadata_revision'] = $this->metadataRevision;
        $payload['tags'] = array_map(
            static fn (McpUntrustedText $tag): array => $tag->toWire(),
            $this->tags,
        );
        $payload['updated_at'] = $this->updatedAt;

        return $payload;
    }
}
