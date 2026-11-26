<?php

declare(strict_types=1);

namespace App\Application\PassageRetrieval;

use App\Domain\PageCatalog\PageType;

final readonly class PreparedVersionPassages
{
    public function __construct(
        public PageType $type,
        public int $generation,
        public int $extractorRevision,
        public string $sourceHash,
        public PassageExtraction $extraction,
    ) {
    }
}
