<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

final class ImageNormalizationLimitExceeded extends ImageNormalizationRejected
{
    public function __construct(int $retryAfterSeconds)
    {
        parent::__construct(
            'Image normalization limit reached. Try again shortly.',
            $retryAfterSeconds,
        );
    }
}
