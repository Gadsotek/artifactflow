<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Output\McpXlsxFactsView;
use App\Application\Mcp\Output\McpXlsxSelectionView;
use App\Application\PageCatalog\XlsxManifestContentReader;
use App\Models\PageVersion;
use App\Models\XlsxVersionFact;

final readonly class McpXlsxVersionPayload
{
    public function __construct(private XlsxManifestContentReader $manifests)
    {
    }

    public function facts(PageVersion $version): ?McpXlsxFactsView
    {
        $facts = $version->xlsxFacts;

        if (!$facts instanceof XlsxVersionFact) {
            return null;
        }

        return new McpXlsxFactsView(
            visibleSheetCount: $facts->visible_sheet_count,
            omittedHiddenSheetCount: $facts->omitted_hidden_sheet_count,
            omittedHiddenRowCount: $facts->omitted_hidden_row_count,
            omittedHiddenColumnCount: $facts->omitted_hidden_column_count,
            cellCount: $facts->cell_count,
            formulaCount: $facts->formula_count,
            uncachedFormulaCount: $facts->uncached_formula_count,
            linkCount: $facts->link_count,
            mergeCount: $facts->merge_count,
            truncated: $facts->truncated,
        );
    }

    public function selection(
        PageVersion $version,
        string $sheetName,
        string $range,
    ): ?McpXlsxSelectionView {
        $manifest = $this->manifests->read($version);

        return is_string($manifest)
            ? McpXlsxSelectionView::fromJson($manifest, $sheetName, $range)
            : null;
    }
}
