<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PdfExtractionState;

final readonly class PersistedPdfExtraction
{
    public function __construct(
        public string $text,
        public PdfExtractionState $state,
    ) {
    }
}
