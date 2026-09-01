<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class XlsxManifestValidationResult
{
    public function __construct(
        public string $manifestJson,
        public string $searchText,
        public int $visibleSheetCount,
        public int $omittedHiddenSheetCount,
        public int $projectedRowExtentCount,
        public int $projectedColumnExtentCount,
        public int $omittedHiddenRowCount,
        public int $omittedHiddenColumnCount,
        public int $cellCount,
        public int $formulaCount,
        public int $formulasWithoutCachedResultCount,
        public int $linkCount,
        public int $mergeCount,
        public bool $truncated,
    ) {
    }
}
