<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;

final readonly class CreatePageCommand
{
    /**
     * @param list<string> $tagNames
     */
    public function __construct(
        public string $workspaceUid,
        public PageType $type,
        public string $title,
        public ?string $description,
        public string $content,
        public PageStatus $status = PageStatus::Draft,
        public ?string $categoryUid = null,
        public ?string $parentPageUid = null,
        public ?string $ownerUserUid = null,
        public array $tagNames = [],
        public ?string $sourceFilename = null,
        public PageVersionSource $source = PageVersionSource::Editor,
        public ?string $categoryName = null,
    ) {
    }
}
