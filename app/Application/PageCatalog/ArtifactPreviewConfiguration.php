<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Infrastructure\Security\OriginNormalizer;
use App\Infrastructure\Security\SecretStrength;
use LogicException;

final class ArtifactPreviewConfiguration
{
    public const int MAX_TTL_SECONDS = 60;

    public function artifactOrigin(): string
    {
        $origin = OriginNormalizer::tryParse($this->stringConfig('app.artifact_url'));

        if ($origin === null) {
            throw new LogicException('Artifact URL must include a scheme and host.');
        }

        return $origin->compact();
    }

    public function signingKey(): string
    {
        $key = $this->stringConfig('app.artifact_url_signing_key');

        if ($key === '') {
            throw new LogicException('Artifact preview signing key is not configured.');
        }

        $normalized = SecretStrength::isStrong($key) ? SecretStrength::normalized($key) : null;

        if ($normalized === null) {
            throw new LogicException('Artifact preview signing key must be a non-placeholder 32-byte secret.');
        }

        return $normalized;
    }

    public function ttlSeconds(): int
    {
        $configuredTtl = config('app.artifact_preview_url_ttl_seconds', self::MAX_TTL_SECONDS);
        $ttlSeconds = is_int($configuredTtl) || is_string($configuredTtl)
            ? (int) $configuredTtl
            : self::MAX_TTL_SECONDS;

        return min(self::MAX_TTL_SECONDS, max(1, $ttlSeconds));
    }

    private function stringConfig(string $key): string
    {
        $value = config($key);

        return is_string($value) ? $value : '';
    }
}
