<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\Provenance\ProvenanceSearchScope;

final readonly class PageSearchFilters
{
    public const string ALL_WORKSPACES = 'all';

    /**
     * @param list<PageStatus> $statuses
     * @param list<string> $categoryUids
     * @param list<string> $tagUids
     * @param list<string> $aiProviders
     * @param list<string> $aiModelIds
     * @param list<PageType> $excludedTypes
     */
    public function __construct(
        public ?string $query,
        public ?string $workspaceUid,
        public ?PageType $type,
        public array $statuses,
        public array $categoryUids,
        public array $tagUids,
        public ?string $ownerUserUid,
        public PageSearchSort $sort,
        public array $aiProviders = [],
        public array $aiModelIds = [],
        public ?string $aiModelQuery = null,
        public ProvenanceSearchScope $provenanceScope = ProvenanceSearchScope::AnyVersion,
        public array $excludedTypes = [],
    ) {
    }

    public function hasQuery(): bool
    {
        return $this->query !== null && $this->query !== '';
    }

    /** @return list<PageStatus> */
    public static function activeStatuses(): array
    {
        return array_filter(
            PageStatus::cases(),
            static fn (PageStatus $status): bool => $status !== PageStatus::Archived,
        );
    }
}
