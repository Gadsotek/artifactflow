<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class DocxConversionResult
{
    public function __construct(
        public string $pdfBytes,
        public int $packageEntryCount,
        public int $expandedBytes,
        public int $relationshipCount,
        public int $mediaCount,
        public int $externalHyperlinkCount,
        public string $processorProfile = DocxProcessorProtocol::PROCESSOR_PROFILE,
        public string $engineName = DocxProcessorProtocol::ENGINE_NAME,
        public string $engineVersion = DocxProcessorProtocol::ENGINE_VERSION,
    ) {
    }
}
