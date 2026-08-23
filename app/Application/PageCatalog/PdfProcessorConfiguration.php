<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Infrastructure\Security\OriginNormalizer;
use App\Infrastructure\Security\SecretStrength;
use App\Infrastructure\Security\UnixSocketPath;
use LogicException;

final readonly class PdfProcessorConfiguration
{
    public const int MAX_INPUT_BYTES = 16 * 1024 * 1024;

    public const int MAX_RESPONSE_BYTES = 16 * 1024 * 1024;

    public const int MAX_TEXT_BYTES = 8 * 1024 * 1024;

    public function enabled(): bool
    {
        $configured = config('pdf_processor.enabled', false);

        if (!is_bool($configured)) {
            throw new LogicException('PDF processor setting [pdf_processor.enabled] must be a boolean.');
        }

        return $configured;
    }

    public function origin(): string
    {
        $origin = OriginNormalizer::tryParsePureOrigin($this->string('pdf_processor.url'));

        if ($origin === null) {
            throw new LogicException('PDF processor URL must be a pure HTTP or HTTPS origin.');
        }

        return $origin->compact();
    }

    public function sharedSecret(): string
    {
        $configured = trim($this->string('pdf_processor.shared_secret'));
        $normalized = SecretStrength::normalized($configured);

        if (
            $normalized === null
            || strlen($normalized) < SecretStrength::MINIMUM_SECRET_BYTES
            || SecretStrength::isPlaceholder($configured)
        ) {
            throw new LogicException('PDF processor shared secret must contain at least 32 non-placeholder bytes.');
        }

        return $normalized;
    }

    public function socketPath(): ?string
    {
        return UnixSocketPath::optional(
            $this->string('pdf_processor.socket_path'),
            'PDF processor socket path',
        );
    }

    public function connectTimeoutSeconds(): int
    {
        return $this->positiveInteger('pdf_processor.connect_timeout_seconds');
    }

    public function timeoutSeconds(): int
    {
        return $this->positiveInteger('pdf_processor.timeout_seconds');
    }

    private function positiveInteger(string $key): int
    {
        $configured = config($key);

        if (!is_int($configured) || $configured < 1 || $configured > 60) {
            throw new LogicException(sprintf(
                'PDF processor setting [%s] must be an integer between 1 and 60.',
                $key,
            ));
        }

        return $configured;
    }

    private function string(string $key): string
    {
        $value = config($key);

        return is_string($value) ? $value : '';
    }
}
