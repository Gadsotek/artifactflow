<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Infrastructure\Security\OriginNormalizer;
use App\Infrastructure\Security\SecretStrength;
use LogicException;

final readonly class ImageParserConfiguration
{
    public function enabled(): bool
    {
        $configured = config('image_parser.enabled', true);

        if (!is_bool($configured)) {
            throw new LogicException('Image parser setting [image_parser.enabled] must be a boolean.');
        }

        return $configured;
    }

    public function origin(): string
    {
        $origin = OriginNormalizer::tryParsePureOrigin($this->string('image_parser.url'));

        if ($origin === null) {
            throw new LogicException('Image parser URL must be a pure HTTP or HTTPS origin.');
        }

        return $origin->compact();
    }

    public function sharedSecret(): string
    {
        $configured = trim($this->string('image_parser.shared_secret'));
        $normalized = SecretStrength::normalized($configured);

        if (
            $normalized === null
            || strlen($normalized) < SecretStrength::MINIMUM_SECRET_BYTES
            || SecretStrength::isPlaceholder($configured)
        ) {
            throw new LogicException('Image parser shared secret must contain at least 32 non-placeholder bytes.');
        }

        return $normalized;
    }

    public function connectTimeoutSeconds(): int
    {
        $seconds = ImageParserTimeouts::connectSeconds(config('image_parser.connect_timeout_seconds'));

        if ($seconds === null) {
            throw new LogicException(
                'Image parser setting [image_parser.connect_timeout_seconds] must be a positive integer.',
            );
        }

        return $seconds;
    }

    public function timeoutSeconds(): int
    {
        $seconds = ImageParserTimeouts::requestSeconds(config('image_parser.timeout_seconds'));

        if ($seconds === null) {
            throw new LogicException(
                'Image parser setting [image_parser.timeout_seconds] must be a positive integer.',
            );
        }

        return $seconds;
    }

    private function string(string $key): string
    {
        $value = config($key);

        return is_string($value) ? $value : '';
    }
}
