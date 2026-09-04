<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpDocxFactsView implements McpWirePayload
{
    public function __construct(
        public int $pageCount,
        public string $extractionState,
        public int $externalHyperlinkCount,
    ) {
    }

    /** @return array<string, int|string> */
    public function toWire(): array
    {
        return [
            'page_count' => $this->pageCount,
            'extraction_state' => $this->extractionState,
            'external_hyperlink_count' => $this->externalHyperlinkCount,
        ];
    }
}
