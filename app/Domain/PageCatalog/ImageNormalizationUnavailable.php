<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

final class ImageNormalizationUnavailable extends ImageNormalizationRejected
{
    public function __construct()
    {
        parent::__construct(
            'Image normalization service is unavailable. Try again shortly.',
            5,
        );
    }
}
