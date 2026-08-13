<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class ImageNormalizationWorkload
{
    public const int STRUCTURE_UNIT_BYTES = 1024;

    public function __construct(
        public int $inputBytes,
        public int $metadataBytes,
        public int $structureCount,
    ) {
    }

    public function units(): int
    {
        return $this->inputBytes
            + $this->metadataBytes
            + ($this->structureCount * self::STRUCTURE_UNIT_BYTES);
    }
}
