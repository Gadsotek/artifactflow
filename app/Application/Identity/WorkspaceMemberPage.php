<?php

declare(strict_types=1);

namespace App\Application\Identity;

final readonly class WorkspaceMemberPage
{
    /**
     * @param list<WorkspaceMemberItem> $items
     */
    public function __construct(
        public array $items,
        public int $currentPage,
        public int $lastPage,
        public int $perPage,
        public int $total,
    ) {
    }

    public function hasPreviousPage(): bool
    {
        return $this->currentPage > 1;
    }

    public function hasNextPage(): bool
    {
        return $this->currentPage < $this->lastPage;
    }
}
