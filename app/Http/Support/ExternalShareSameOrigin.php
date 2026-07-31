<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Infrastructure\Security\OriginNormalizer;
use Illuminate\Http\Request;

final class ExternalShareSameOrigin
{
    public function accepts(Request $request): bool
    {
        $origin = $request->headers->get('Origin');
        $fetchSite = strtolower(trim((string) $request->headers->get('Sec-Fetch-Site', '')));
        $configuredOrigin = config('app.url');

        if (!is_string($origin) || !is_string($configuredOrigin) || $fetchSite !== 'same-origin') {
            return false;
        }

        $requestOrigin = OriginNormalizer::tryParse($origin);
        $appOrigin = OriginNormalizer::tryParse($configuredOrigin);

        return $requestOrigin !== null
            && $appOrigin !== null
            && hash_equals($appOrigin->compact(), $requestOrigin->compact());
    }
}
