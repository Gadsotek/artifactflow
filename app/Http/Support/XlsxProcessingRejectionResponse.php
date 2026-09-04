<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domain\PageCatalog\XlsxProcessingRejected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class XlsxProcessingRejectionResponse
{
    public static function make(
        XlsxProcessingRejected $exception,
        Request $request,
        string $field,
    ): RedirectResponse {
        return redirect()
            ->back(303)
            ->withErrors([$field => $exception->getMessage()])
            ->withInput($request->except('_token'))
            ->header('Retry-After', (string) $exception->retryAfterSeconds)
            ->header('Cache-Control', 'no-store');
    }
}
