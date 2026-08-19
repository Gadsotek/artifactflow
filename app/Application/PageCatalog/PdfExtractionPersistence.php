<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PdfExtractionState;

final readonly class PdfExtractionPersistence
{
    public function fromResult(PdfProcessingResult $result): PersistedPdfExtraction
    {
        $limit = PageSearchVectorUpdater::MAX_EXTRACTED_TEXT_SEARCH_CHARACTERS;
        $wasApplicationTruncated = mb_strlen($result->text) > $limit;

        return new PersistedPdfExtraction(
            text: mb_substr($result->text, 0, $limit),
            state: $wasApplicationTruncated && $result->extractionState === PdfExtractionState::Indexed
                ? PdfExtractionState::PartiallyIndexed
                : $result->extractionState,
        );
    }
}
