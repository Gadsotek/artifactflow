<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PageVersionDiffLineKind;

final readonly class PageVersionDiffLine
{
    public function __construct(
        public PageVersionDiffLineKind $kind,
        public ?int $oldLineNumber,
        public ?int $newLineNumber,
        public string $content,
        public int $omittedLineCount = 0,
    ) {
    }
}
