<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class ImageParserTimeouts
{
    private const int DEFAULT_CONNECT_SECONDS = 2;

    private const int MAX_CONNECT_SECONDS = 10;

    private const int DEFAULT_REQUEST_SECONDS = 12;

    private const int MAX_REQUEST_SECONDS = 30;

    private function __construct(
        public int $connectSeconds,
        public int $requestSeconds,
    ) {
    }

    public static function tryFrom(mixed $connect, mixed $request): ?self
    {
        $connectSeconds = self::connectSeconds($connect);
        $requestSeconds = self::requestSeconds($request);

        if ($connectSeconds === null || $requestSeconds === null) {
            return null;
        }

        return new self($connectSeconds, $requestSeconds);
    }

    public static function connectSeconds(mixed $configured): ?int
    {
        return self::boundedPositiveInt(
            $configured,
            self::DEFAULT_CONNECT_SECONDS,
            self::MAX_CONNECT_SECONDS,
        );
    }

    public static function requestSeconds(mixed $configured): ?int
    {
        return self::boundedPositiveInt(
            $configured,
            self::DEFAULT_REQUEST_SECONDS,
            self::MAX_REQUEST_SECONDS,
        );
    }

    private static function boundedPositiveInt(mixed $configured, int $default, int $maximum): ?int
    {
        if ($configured === null) {
            return $default;
        }

        $value = PositiveIntegerConfiguration::tryFrom($configured);

        if ($value === null) {
            return null;
        }

        return min($maximum, $value);
    }
}
