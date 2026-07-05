<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PageVersionSource;

final readonly class UpdatePageContentCommand
{
    public function __construct(
        public string $pageUid,
        public string $content,
        public PageVersionSource $source = PageVersionSource::Editor,
        public ?string $baseVersionUid = null,
    ) {
    }
}
