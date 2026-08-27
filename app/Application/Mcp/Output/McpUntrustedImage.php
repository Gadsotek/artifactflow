<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpUntrustedImage implements McpWirePayload
{
    public function __construct(public string $mediaType)
    {
    }

    /**
     * @return array{prompt_read_first: string, kind: string, media_type: string, transport: string}
     */
    public function toWire(): array
    {
        return [
            'prompt_read_first' => McpUntrustedText::PROMPT_READ_FIRST,
            'kind' => 'artifactflow.untrusted_data',
            'media_type' => $this->mediaType,
            'transport' => 'mcp_image_content',
        ];
    }
}
