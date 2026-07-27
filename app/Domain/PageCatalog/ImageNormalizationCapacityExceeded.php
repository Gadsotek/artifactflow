<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

final class ImageNormalizationCapacityExceeded extends ImageNormalizationRejected
{
    public function __construct(int $retryAfterSeconds)
    {
        parent::__construct(
            'Image normalization capacity is temporarily exhausted. Try again shortly.',
            $retryAfterSeconds,
        );
    }
}
