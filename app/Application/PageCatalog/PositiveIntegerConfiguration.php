<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final class PositiveIntegerConfiguration
{
    public static function isIntegerLike(mixed $configured): bool
    {
        return is_int($configured)
            || (is_string($configured) && ctype_digit($configured));
    }

    public static function tryFrom(mixed $configured): ?int
    {
        if (is_int($configured)) {
            $asString = (string) $configured;
        } elseif (is_string($configured) && ctype_digit($configured)) {
            $asString = $configured;
        } else {
            return null;
        }

        $normalized = ltrim($asString, '0');

        if ($normalized === '') {
            return null;
        }

        $maximum = (string) PHP_INT_MAX;

        if (
            strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)
        ) {
            return null;
        }

        $value = (int) $normalized;

        return $value >= 1 ? $value : null;
    }
}
