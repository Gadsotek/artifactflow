<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class NormalizedRasterImage
{
    public function __construct(
        public string $bytes,
        public RasterImageInfo $info,
    ) {
    }
}
