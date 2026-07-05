<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Neutralizes user-controlled text that is interpolated into a Markdown
 * document (for example a Markdown mail body). Blade's `{{ }}` escaping only
 * covers HTML; it leaves Markdown syntax intact, so a value such as
 * `[Trusted link](https://phishing.example)` would render as a real, disguised
 * hyperlink once the document is parsed as Markdown. Backslash-escaping the
 * Markdown metacharacters makes the value render verbatim.
 */
final class MarkdownText
{
    /**
     * Characters that Blade's `{{ }}` (htmlspecialchars with ENT_QUOTES) already
     * turns into HTML entities before the Markdown pass runs. Backslash-escaping
     * them here would collide with the entity that Blade produces, so they are
     * left for Blade to neutralize.
     *
     * @var list<string>
     */
    private const array HTML_ESCAPED = ['&', '<', '>', '"', "'"];

    public static function escapeInline(string $value): string
    {
        // CommonMark lets any ASCII punctuation character be backslash-escaped to
        // render literally. `[[:punct:]]` matches only ASCII punctuation (byte
        // 0x21-0x7E), so multibyte UTF-8 sequences pass through untouched.
        return preg_replace_callback(
            '/[[:punct:]]/',
            static fn (array $match): string => in_array($match[0], self::HTML_ESCAPED, true)
                ? $match[0]
                : '\\' . $match[0],
            $value,
        ) ?? $value;
    }
}
