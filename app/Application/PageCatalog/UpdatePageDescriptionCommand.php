<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class UpdatePageDescriptionCommand
{
    public function __construct(
        public string $pageUid,
        public string $expectedCurrentVersionUid,
        public int $expectedMetadataRevision,
        public ?string $description,
    ) {
    }
}
