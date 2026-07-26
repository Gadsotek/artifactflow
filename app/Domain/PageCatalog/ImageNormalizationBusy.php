<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

final class ImageNormalizationBusy extends ImageNormalizationRejected
{
    public function __construct(int $retryAfterSeconds)
    {
        parent::__construct(
            'Image normalization is busy. Try again shortly.',
            $retryAfterSeconds,
        );
    }
}
