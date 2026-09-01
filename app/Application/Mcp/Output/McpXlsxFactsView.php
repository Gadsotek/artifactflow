<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpXlsxFactsView implements McpWirePayload
{
    public function __construct(
        public int $visibleSheetCount,
        public int $omittedHiddenSheetCount,
        public int $omittedHiddenRowCount,
        public int $omittedHiddenColumnCount,
        public int $cellCount,
        public int $formulaCount,
        public int $uncachedFormulaCount,
        public int $linkCount,
        public int $mergeCount,
        public bool $truncated,
    ) {
    }

    /** @return array<string, int|bool> */
    public function toWire(): array
    {
        return [
            'visible_sheet_count' => $this->visibleSheetCount,
            'omitted_hidden_sheet_count' => $this->omittedHiddenSheetCount,
            'omitted_hidden_row_count' => $this->omittedHiddenRowCount,
            'omitted_hidden_column_count' => $this->omittedHiddenColumnCount,
            'cell_count' => $this->cellCount,
            'formula_count' => $this->formulaCount,
            'uncached_formula_count' => $this->uncachedFormulaCount,
            'link_count' => $this->linkCount,
            'merge_count' => $this->mergeCount,
            'truncated' => $this->truncated,
        ];
    }
}
