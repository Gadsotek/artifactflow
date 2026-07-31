<?php

declare(strict_types=1);

namespace App\Application\ExternalSharing;

use App\Domain\ExternalSharing\ExternalPagePresentation;
use App\Models\PageVersion;

final readonly class ExternalShareViewerPage
{
    public function __construct(
        public ExternalShareViewContext $context,
        public PageVersion $version,
        public ExternalPagePresentation $presentation,
        public ?string $renderedMarkdown,
        public ?string $artifactPreviewUrl,
    ) {
    }
}
