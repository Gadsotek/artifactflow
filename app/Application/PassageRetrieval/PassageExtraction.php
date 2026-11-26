<?php

declare(strict_types=1);

namespace App\Application\PassageRetrieval;

final readonly class PassageExtraction
{
    /**
     * @param list<SourcePassage> $passages
     */
    public function __construct(
        public ExtractionOutcome $outcome,
        public array $passages,
    ) {
    }
}
