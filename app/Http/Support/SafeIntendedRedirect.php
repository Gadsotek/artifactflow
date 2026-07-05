<?php

declare(strict_types=1);

namespace App\Http\Support;

use Illuminate\Http\Request;

final readonly class SafeIntendedRedirect
{
    public function forgetUnsafeIntendedUrl(Request $request): void
    {
        $intendedUrl = $request->session()->get('url.intended');

        if (!is_string($intendedUrl)) {
            return;
        }

        if ($this->isSafeRelativePath($intendedUrl)) {
            return;
        }

        $request->session()->forget('url.intended');
    }

    private function isSafeRelativePath(string $intendedUrl): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $intendedUrl) !== 1
            && str_starts_with($intendedUrl, '/')
            && !str_starts_with($intendedUrl, '//')
            && !str_starts_with($intendedUrl, '/\\');
    }
}
