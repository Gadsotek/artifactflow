<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpImageSearchabilityView implements McpWirePayload
{
    public function __construct(public bool $descriptionMissing)
    {
    }

    /**
     * @return array{
     *     ocr_indexed: false,
     *     description_indexed: true,
     *     description_status: 'missing'|'present',
     *     recommended_tool: 'update_description'|null
     * }
     */
    public function toWire(): array
    {
        return [
            'ocr_indexed' => false,
            'description_indexed' => true,
            'description_status' => $this->descriptionMissing ? 'missing' : 'present',
            'recommended_tool' => $this->descriptionMissing ? 'update_description' : null,
        ];
    }
}
