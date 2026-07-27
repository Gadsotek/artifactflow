<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class PageContentTextProjection
{
    public function __construct(
        public ?string $extractedText,
        public ?string $sourceText,
    ) {
    }
}
