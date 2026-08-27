<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpExternalReferenceView implements McpWirePayload
{
    public function __construct(
        public string $kind,
        public ?McpUntrustedText $reference,
        public ?McpUntrustedText $url,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $payload = ['kind' => $this->kind];

        if ($this->reference !== null) {
            $payload['ref'] = $this->reference->toWire();
        }

        if ($this->url !== null) {
            $payload['url'] = $this->url->toWire();
        }

        return $payload;
    }
}
