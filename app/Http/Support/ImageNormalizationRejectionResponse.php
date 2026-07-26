<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domain\PageCatalog\ImageNormalizationLimitExceeded;
use App\Domain\PageCatalog\ImageNormalizationRejected;
use Illuminate\Http\Response;

final class ImageNormalizationRejectionResponse
{
    public static function make(ImageNormalizationRejected $exception): Response
    {
        $status = $exception instanceof ImageNormalizationLimitExceeded ? 429 : 503;

        return response($exception->getMessage(), $status)
            ->header('Retry-After', (string) $exception->retryAfterSeconds)
            ->header('Cache-Control', 'no-store');
    }
}
