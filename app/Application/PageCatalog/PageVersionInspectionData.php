<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\PageVersion;

final readonly class PageVersionInspectionData
{
    public function __construct(
        public PageVersion $version,
        public PageVersion $currentVersion,
        public ?PageVersion $olderVersion,
        public ?PageVersion $newerVersion,
        public ?string $renderedMarkdown,
        public ?string $artifactPreviewUrl,
        public bool $contentUnavailable,
        public bool $comparisonUnavailable,
        public PageVersionDiffResult $diff,
        public bool $canRestore,
    ) {
    }
}
