<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class DocxProcessingResult
{
    public function __construct(
        public DocxConversionResult $conversion,
        public PdfProcessingResult $pdf,
    ) {
    }
}
