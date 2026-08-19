<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PdfExtractionState;

final readonly class PdfExtractionStatusView
{
    public function __construct(
        public string $stateValue,
        public string $label,
    ) {
    }

    public static function fromState(PdfExtractionState $state): self
    {
        return new self(
            stateValue: $state->value,
            label: match ($state) {
                PdfExtractionState::Indexed => 'Indexed',
                PdfExtractionState::NoEmbeddedText => 'No embedded text',
                PdfExtractionState::PartiallyIndexed => 'Partially indexed',
            },
        );
    }
}
