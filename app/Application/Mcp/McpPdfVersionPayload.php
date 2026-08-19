<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Models\PageVersion;
use App\Models\PdfVersionFact;

final readonly class McpPdfVersionPayload
{
    /**
     * Processor profiles and storage details are deliberately excluded: MCP
     * receives only facts useful for understanding search completeness.
     *
     * @return array{
     *     page_count: int,
     *     pdf_version: string,
     *     extraction_state: string,
     *     ocr_indexed: false
     * }|null
     */
    public function forVersion(PageVersion $version): ?array
    {
        $facts = $version->pdfFacts()->first();

        if (!$facts instanceof PdfVersionFact) {
            return null;
        }

        return [
            'page_count' => $facts->page_count,
            'pdf_version' => $facts->pdf_version,
            'extraction_state' => $facts->extraction_state->value,
            'ocr_indexed' => false,
        ];
    }
}
