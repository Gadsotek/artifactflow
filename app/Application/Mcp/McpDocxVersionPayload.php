<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Output\McpDocxFactsView;
use App\Models\DocxVersionFact;
use App\Models\PageVersion;

final readonly class McpDocxVersionPayload
{
    public function facts(PageVersion $version): ?McpDocxFactsView
    {
        $facts = $version->docxFacts;

        return $facts instanceof DocxVersionFact
            ? new McpDocxFactsView(
                pageCount: $facts->page_count,
                extractionState: $facts->extraction_state->value,
                externalHyperlinkCount: $facts->external_hyperlink_count,
            )
            : null;
    }
}
