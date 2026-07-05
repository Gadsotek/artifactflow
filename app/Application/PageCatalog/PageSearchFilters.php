<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;

final readonly class PageSearchFilters
{
    public const string ALL_WORKSPACES = 'all';

    /**
     * @param list<string> $tagUids
     */
    public function __construct(
        public ?string $query,
        public ?string $workspaceUid,
        public ?PageType $type,
        public ?PageStatus $status,
        public ?string $categoryUid,
        public array $tagUids,
        public ?string $ownerUserUid,
        public bool $includeArchived,
        public PageSearchSort $sort,
    ) {
    }

    public function hasQuery(): bool
    {
        return $this->query !== null && $this->query !== '';
    }
}
