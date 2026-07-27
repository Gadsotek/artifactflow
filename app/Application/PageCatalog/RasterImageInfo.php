<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class RasterImageInfo
{
    public function __construct(
        public string $mediaType,
        public int $width,
        public int $height,
    ) {
    }

    public function extension(): string
    {
        return $this->mediaType === 'image/jpeg' ? 'jpg' : 'png';
    }

    public function pixels(): int
    {
        return $this->width * $this->height;
    }
}
