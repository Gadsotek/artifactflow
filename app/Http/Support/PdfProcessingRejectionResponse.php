<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domain\PageCatalog\PdfProcessingRejected;
use Illuminate\Http\Response;

final class PdfProcessingRejectionResponse
{
    public static function make(PdfProcessingRejected $exception): Response
    {
        return response($exception->getMessage(), 503)
            ->header('Retry-After', (string) $exception->retryAfterSeconds)
            ->header('Cache-Control', 'no-store');
    }
}
