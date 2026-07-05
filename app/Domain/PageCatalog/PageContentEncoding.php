<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

/**
 * Encoding guard for stored page content. The artifact file on disk tolerates
 * arbitrary bytes, but the derived `source_text` / `extracted_text` columns are
 * PostgreSQL text and reject a NUL byte or malformed UTF-8 with a hard error.
 * Screening here turns that class of input into a clean validation failure at
 * the request boundary instead of a 500 at write time.
 *
 * Tab (0x09), line feed (0x0A) and carriage return (0x0D) are legitimate text
 * and remain allowed; every other C0 control byte (NUL through 0x1F) is not.
 */
final class PageContentEncoding
{
    private const string CONTROL_BYTE_PATTERN = '/[\x00-\x08\x0B\x0C\x0E-\x1F]/';

    public static function isValidUtf8(string $content): bool
    {
        return mb_check_encoding($content, 'UTF-8');
    }

    public static function containsControlBytes(string $content): bool
    {
        return preg_match(self::CONTROL_BYTE_PATTERN, $content) === 1;
    }

    public static function isStorable(string $content): bool
    {
        return self::isValidUtf8($content) && !self::containsControlBytes($content);
    }
}
