<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

/**
 * A parsed, normalized web origin (scheme + host + resolved port). Built only
 * through {@see OriginNormalizer} so every security boundary shares one parsing
 * and validation rule instead of hand-rolling its own.
 *
 * Two rendering forms are exposed because the codebase legitimately needs both:
 * {@see self::canonical()} always carries the explicit port and is used for
 * exact origin-equality checks (production boot gate, deployment preflight),
 * while {@see self::compact()} omits default ports and is used for CSP
 * directives and browser-facing origin strings.
 */
final readonly class Origin
{
    public function __construct(
        public string $scheme,
        public string $host,
        public int $port,
    ) {
    }

    public function canonical(): string
    {
        return sprintf('%s://%s:%d', $this->scheme, $this->host, $this->port);
    }

    public function compact(): string
    {
        if ($this->isDefaultPort()) {
            return sprintf('%s://%s', $this->scheme, $this->host);
        }

        return sprintf('%s://%s:%d', $this->scheme, $this->host, $this->port);
    }

    public function isHttps(): bool
    {
        return $this->scheme === 'https';
    }

    private function isDefaultPort(): bool
    {
        return ($this->scheme === 'http' && $this->port === 80)
            || ($this->scheme === 'https' && $this->port === 443);
    }
}
