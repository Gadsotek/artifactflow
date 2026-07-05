<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class PageVersionDiffResult
{
    /**
     * @param list<PageVersionDiffLine> $lines
     */
    public function __construct(
        public array $lines,
        public int $addedLines,
        public int $removedLines,
        public bool $tooLarge,
    ) {
    }
}
