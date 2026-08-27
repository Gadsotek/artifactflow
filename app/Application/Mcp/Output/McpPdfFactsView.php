<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpPdfFactsView implements McpWirePayload
{
    public function __construct(
        public int $pageCount,
        public string $pdfVersion,
        public string $extractionState,
    ) {
    }

    /**
     * @return array{page_count: int, pdf_version: string, extraction_state: string, ocr_indexed: false}
     */
    public function toWire(): array
    {
        return [
            'page_count' => $this->pageCount,
            'pdf_version' => $this->pdfVersion,
            'extraction_state' => $this->extractionState,
            'ocr_indexed' => false,
        ];
    }
}
