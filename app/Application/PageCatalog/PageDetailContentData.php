<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\PageVersion;

final readonly class PageDetailContentData
{
    public function __construct(
        public ?PageVersion $version,
        public ?string $sourcePreview,
        public ?string $renderedMarkdown,
        public ?string $renderedEditorMarkdown,
        public ?string $artifactPreviewUrl,
        public bool $contentUnavailable,
    ) {
    }
}
