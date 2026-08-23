<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use LogicException;

final class UnixSocketPath
{
    private function __construct()
    {
    }

    public static function optional(string $configured, string $label): ?string
    {
        if (!self::isValidOptional($configured)) {
            throw new LogicException(sprintf('%s must be an absolute filesystem path.', $label));
        }

        $path = trim($configured);

        return $path === '' ? null : $path;
    }

    public static function isValidOptional(string $configured): bool
    {
        if (str_contains($configured, "\0")) {
            return false;
        }

        $path = trim($configured);

        return $path === ''
            || str_starts_with($path, '/');
    }
}
