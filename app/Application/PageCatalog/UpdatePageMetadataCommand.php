<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class UpdatePageMetadataCommand
{
    /**
     * @param list<string> $tagNames
     */
    public function __construct(
        public string $pageUid,
        public int $expectedMetadataRevision,
        public string $title,
        public ?string $description,
        public ?string $categoryUid,
        public ?string $parentPageUid,
        public string $ownerUserUid,
        public array $tagNames,
    ) {
    }
}
