<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Infrastructure\Security\OriginNormalizer;

final readonly class ArtifactFramePolicy
{
    public function frameAncestors(): string
    {
        $configured = $this->stringConfig('app.artifact_frame_ancestors');

        if ($configured === '') {
            $configured = $this->stringConfig('app.url');
        }

        $normalized = preg_replace('/\s+/', ' ', str_replace(',', ' ', trim($configured)));

        return is_string($normalized) && $normalized !== '' ? $normalized : "'none'";
    }

    public function appOrigin(): string
    {
        $frameAncestors = preg_split(
            '/[\s,]+/',
            $this->stringConfig('app.artifact_frame_ancestors'),
            -1,
            PREG_SPLIT_NO_EMPTY,
        );

        if (is_array($frameAncestors) && count($frameAncestors) === 1) {
            $origin = OriginNormalizer::tryParse($frameAncestors[0]);

            if ($origin !== null) {
                return $origin->compact();
            }
        }

        return $this->stringConfig('app.url');
    }

    private function stringConfig(string $key): string
    {
        $value = config($key);

        return is_string($value) ? $value : '';
    }
}
