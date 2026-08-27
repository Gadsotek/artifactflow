<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Output\McpPdfFactsView;
use App\Models\PageVersion;
use App\Models\PdfVersionFact;

final readonly class McpPdfVersionPayload
{
    /**
     * Processor profiles and storage details are deliberately excluded: MCP
     * receives only facts useful for understanding search completeness.
     *
     */
    public function forVersion(PageVersion $version): ?McpPdfFactsView
    {
        $facts = $version->pdfFacts()->first();

        if (!$facts instanceof PdfVersionFact) {
            return null;
        }

        return new McpPdfFactsView(
            pageCount: $facts->page_count,
            pdfVersion: $facts->pdf_version,
            extractionState: $facts->extraction_state->value,
        );
    }
}
