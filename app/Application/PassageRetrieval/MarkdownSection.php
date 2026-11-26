<?php

declare(strict_types=1);

namespace App\Application\PassageRetrieval;

/** Groups adjacent original source lines under their current heading path. */
final class MarkdownSection
{
    /** @var array<int, string> */
    private array $headings = [];

    /** @var array{int, int, int, int}|null */
    private ?array $headingOnly = null;

    private ?int $start = null;
    private int $end = 0;
    private int $firstLine = 0;
    private int $lastLine = 0;

    public function __construct(
        private readonly string $source,
        private readonly PassageCollector $collector,
    ) {
    }

    public function heading(int $level, string $title, int $start, int $end, int $firstLine, int $lastLine): void
    {
        $this->flush();
        if ($this->headingOnly !== null && $level <= array_key_last($this->headings)) {
            $this->emit(...$this->headingOnly);
        }
        $this->headings = array_filter($this->headings, static fn (int $key): bool => $key < $level, ARRAY_FILTER_USE_KEY);
        if (strlen($title) > PassageCollector::MAX_EXCERPT_BYTES) {
            $title = mb_strcut($title, 0, PassageCollector::MAX_EXCERPT_BYTES, 'UTF-8');
            $this->collector->markLimited();
        }
        if ($title !== '') {
            $this->headings[$level] = $title;
        }

        $this->headingOnly = $title !== '' && $end - $start <= PassageCollector::MAX_EXCERPT_BYTES
            ? [$start, $end, $firstLine, $lastLine]
            : null;
    }

    public function block(MarkdownSourceLines $lines, int $first, int $last): void
    {
        [$start] = $lines->offsets($first);
        [, $end] = $lines->offsets($last);
        if ($this->start !== null && $end - $this->start > PassageCollector::MAX_EXCERPT_BYTES) {
            $this->flush();
        }
        for ($line = $first; $line <= $last && $this->collector->advance(); $line++) {
            [$start, $end] = $lines->offsets($line);
            $this->append($start, $end, $line);
        }
    }

    private function append(int $start, int $end, int $line): void
    {
        if ($end === $start) {
            return;
        }
        $this->headingOnly = null;
        if ($this->start !== null && $end - $this->start > PassageCollector::MAX_EXCERPT_BYTES) {
            $this->flush();
        }

        while ($end - $start > PassageCollector::MAX_EXCERPT_BYTES) {
            if (!$this->collector->advance()) {
                return;
            }
            $cut = $start + PassageCollector::MAX_EXCERPT_BYTES;
            while ((ord($this->source[$cut]) & 0xc0) === 0x80) {
                $cut--;
            }
            $this->emit($start, $cut, $line, $line);
            $start = $cut;
        }

        if ($this->start === null) {
            $this->start = $start;
            $this->firstLine = $line;
        }
        $this->end = $end;
        $this->lastLine = $line;
    }

    public function finish(): void
    {
        $this->flush();
        if ($this->headingOnly !== null) {
            $this->emit(...$this->headingOnly);
        }
    }

    private function flush(): void
    {
        if ($this->start !== null) {
            $this->emit($this->start, $this->end, $this->firstLine, $this->lastLine);
            $this->start = null;
        }
    }

    private function emit(int $start, int $end, int $firstLine, int $lastLine): void
    {
        $this->collector->add(new SourcePassage(
            array_values($this->headings),
            substr($this->source, $start, $end - $start),
            [
                'kind' => 'markdown_source',
                'start_line' => $firstLine,
                'end_line' => $lastLine,
                'start_byte' => $start,
                'end_byte' => $end,
            ],
        ));
    }
}
