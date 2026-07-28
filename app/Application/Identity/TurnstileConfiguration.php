<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\PageCatalog\PositiveIntegerConfiguration;
use App\Infrastructure\Security\OriginNormalizer;
use Illuminate\Contracts\Config\Repository;
use RuntimeException;

final readonly class TurnstileConfiguration
{
    public const string ORIGIN = 'https://challenges.cloudflare.com';

    public const string SCRIPT_URL = self::ORIGIN . '/turnstile/v0/api.js';

    public const string VERIFY_URL = self::ORIGIN . '/turnstile/v0/siteverify';

    public const string ACTION_LOGIN = 'login';

    public const string ACTION_PASSWORD_RESET_REQUEST = 'password_reset_request';

    public const string ACTION_PASSWORD_RESET = 'password_reset';

    public const int MAX_TOKEN_LENGTH = 2048;

    public const string TEST_SITE_KEY = '1x00000000000000000000AA';

    public const string TEST_SECRET_KEY = '1x0000000000000000000000000000000AA';

    /**
     * @var list<string>
     */
    private const array TEST_SITE_KEYS = [
        self::TEST_SITE_KEY,
        '2x00000000000000000000AB',
        '1x00000000000000000000BB',
        '2x00000000000000000000BB',
        '3x00000000000000000000FF',
    ];

    /**
     * @var list<string>
     */
    private const array TEST_SECRET_KEYS = [
        self::TEST_SECRET_KEY,
        '2x0000000000000000000000000000000AA',
        '3x0000000000000000000000000000000AA',
    ];

    private const int MAX_CONNECT_TIMEOUT_SECONDS = 10;

    private const int MAX_REQUEST_TIMEOUT_SECONDS = 15;

    public function __construct(
        private Repository $config,
    ) {
    }

    public function enabled(): bool
    {
        if ($this->hasPartialCredentials()) {
            throw new RuntimeException(
                'TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY must be configured together.',
            );
        }

        return $this->hasCompleteCredentials();
    }

    public function publicSiteKey(): ?string
    {
        return $this->enabled() ? $this->siteKey() : null;
    }

    public function secret(): string
    {
        if (!$this->enabled()) {
            throw new RuntimeException('Cloudflare Turnstile is not configured.');
        }

        return $this->secretKey();
    }

    public function expectedHostname(): string
    {
        $configured = strtolower($this->string('turnstile.expected_hostname'));
        $host = OriginNormalizer::tryHost($configured);

        if (!$this->enabled() || $host === null || $host !== $configured) {
            throw new RuntimeException('TURNSTILE_EXPECTED_HOSTNAME must be a valid hostname.');
        }

        return $host;
    }

    public function connectTimeoutSeconds(): int
    {
        return $this->timeout(
            'turnstile.connect_timeout_seconds',
            self::MAX_CONNECT_TIMEOUT_SECONDS,
        );
    }

    public function requestTimeoutSeconds(): int
    {
        return $this->timeout(
            'turnstile.timeout_seconds',
            self::MAX_REQUEST_TIMEOUT_SECONDS,
        );
    }

    public function hasAnyCredentials(): bool
    {
        return $this->siteKey() !== '' || $this->secretKey() !== '';
    }

    public function hasCompleteCredentials(): bool
    {
        return $this->siteKey() !== '' && $this->secretKey() !== '';
    }

    public function hasPartialCredentials(): bool
    {
        return $this->hasAnyCredentials() && !$this->hasCompleteCredentials();
    }

    public function usesOfficialTestCredentials(): bool
    {
        return in_array($this->siteKey(), self::TEST_SITE_KEYS, true)
            || in_array($this->secretKey(), self::TEST_SECRET_KEYS, true);
    }

    private function timeout(string $key, int $maximum): int
    {
        $seconds = PositiveIntegerConfiguration::tryFrom($this->config->get($key));

        if ($seconds === null) {
            throw new RuntimeException(
                'Turnstile connect and request timeouts must be positive integers.',
            );
        }

        return min($seconds, $maximum);
    }

    private function siteKey(): string
    {
        return $this->string('turnstile.site_key');
    }

    private function secretKey(): string
    {
        return $this->string('turnstile.secret_key');
    }

    private function string(string $key): string
    {
        $value = $this->config->get($key);

        return is_string($value) ? trim($value) : '';
    }
}
