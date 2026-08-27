<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpStoredProvenanceReceipt implements McpWirePayload
{
    /**
     * @param list<McpProducerView> $directVersionProducers
     */
    public function __construct(
        public bool $supplied,
        public string $completeness,
        public array $directVersionProducers,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'supplied' => $this->supplied,
            'completeness' => $this->completeness,
            'direct_version_producers' => array_map(
                static fn (McpProducerView $producer): array => $producer->toWire(),
                $this->directVersionProducers,
            ),
        ];
    }
}
