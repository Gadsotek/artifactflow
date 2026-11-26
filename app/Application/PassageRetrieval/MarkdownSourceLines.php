<?php

declare(strict_types=1);

namespace App\Application\PassageRetrieval;

use League\CommonMark\Input\MarkdownInputInterface;

/** Records the original byte coordinates while feeding bounded lines to CommonMark. */
final class MarkdownSourceLines implements MarkdownInputInterface
{
    private const int MAX_LINES = 4096;
    private const int MAX_BLOCK_PREFIX_WORK = 16_384;

    /** @var array<int, array{int, int}> */
    private array $offsets = [];
    public private(set) bool $truncated = false;

    public function __construct(
        private readonly string $source,
        private readonly PassageCollector $collector,
    ) {
    }

    public function getContent(): string
    {
        return $this->source;
    }

    public function getLineCount(): int
    {
        return substr_count($this->source, "\n") + substr_count($this->source, "\r")
            - substr_count($this->source, "\r\n") + (preg_match('/[^\r\n]\z/', $this->source) === 1 ? 1 : 0);
    }

    /** @return iterable<int, string> */
    public function getLines(): iterable
    {
        $number = 0;
        $prefixWork = 0;
        $length = strlen($this->source);
        for ($offset = 0; $offset < $length; $offset = $next) {
            $end = $offset + strcspn($this->source, "\r\n", $offset);
            $next = $end + (($this->source[$end] ?? '') === "\r" && ($this->source[$end + 1] ?? '') === "\n" ? 2 : 1);
            $line = substr($this->source, $offset, $end - $offset);
            // Count indentation and possible container prefixes before a line
            // can create nested list or blockquote nodes in the parser.
            $prefixWork += 1 + min(PassageCollector::MAX_DEPTH, strspn($line, " \t>+-*.0123456789)"));
            if ($number >= self::MAX_LINES || $prefixWork > self::MAX_BLOCK_PREFIX_WORK || !$this->collector->advance()) {
                $this->truncated = true;
                $this->collector->markLimited();

                return;
            }
            $this->offsets[++$number] = [$offset, $end];

            yield $number => $number === 1 && str_starts_with($line, "\xef\xbb\xbf") ? substr($line, 3) : $line;
        }
    }

    /** @return array{int, int} */
    public function offsets(int $line): array
    {
        return $this->offsets[$line];
    }

    public function blank(int $line): bool
    {
        [$start, $end] = $this->offsets[$line];

        return strspn($this->source, " \t", $start, $end - $start) === $end - $start;
    }
}
