<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

enum PdfExtractionState: string
{
    case Indexed = 'indexed';
    case NoEmbeddedText = 'no_embedded_text';
    case PartiallyIndexed = 'partially_indexed';
}
