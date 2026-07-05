<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Infrastructure\Security\OriginNormalizer;
use App\Infrastructure\Security\SecretStrength;
use Throwable;

final readonly class RealtimeConfiguration
{
    public function __construct(
        private InstallationLimitSettings $settings,
    ) {
    }

    public function enabled(): bool
    {
        try {
            return $this->settings->current()->realtimeEnabled && $this->configured();
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    public function configured(): bool
    {
        return $this->stringConfig('broadcasting.default') === 'reverb'
            && $this->stringConfig('broadcasting.connections.reverb.app_id') !== ''
            && $this->stringConfig('broadcasting.connections.reverb.key') !== ''
            && $this->validSecret($this->stringConfig('broadcasting.connections.reverb.secret'))
            && $this->originFromUrl($this->stringConfig('app.reverb_url')) !== null;
    }

    /**
     * @return array{enabled: true, key: string, host: string, port: int, scheme: string}|null
     */
    public function clientConfig(): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        $origin = OriginNormalizer::tryParse($this->stringConfig('app.reverb_url'));

        if ($origin === null) {
            return null;
        }

        return [
            'enabled' => true,
            'key' => $this->stringConfig('broadcasting.connections.reverb.key'),
            'host' => $origin->host,
            'port' => $origin->port,
            'scheme' => $origin->scheme,
        ];
    }

    public function websocketOrigin(): ?string
    {
        if (!$this->enabled()) {
            return null;
        }

        $origin = $this->originFromUrl($this->stringConfig('app.reverb_url'));

        if ($origin === null) {
            return null;
        }

        return str_starts_with($origin, 'https://')
            ? 'wss://' . substr($origin, strlen('https://'))
            : 'ws://' . substr($origin, strlen('http://'));
    }

    private function originFromUrl(string $url): ?string
    {
        return OriginNormalizer::tryParse($url)?->compact();
    }

    private function validSecret(string $secret): bool
    {
        return SecretStrength::isStrong($secret);
    }

    private function stringConfig(string $key): string
    {
        $value = config($key);

        return is_string($value) ? trim($value) : '';
    }
}
