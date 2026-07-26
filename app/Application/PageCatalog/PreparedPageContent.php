<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class PreparedPageContent
{
    public function __construct(
        public string $content,
        public ContentSecurityScan $scan,
        public string $storageFilename,
        public PageContentTextProjection $textProjection,
    ) {
    }
}
