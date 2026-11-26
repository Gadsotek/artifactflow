<?php

declare(strict_types=1);

namespace App\Application\PassageRetrieval;

final class PassageCollector
{
    public const int MAX_EXCERPT_BYTES = 4096;
    public const int MAX_RANGES = 48;
    public const int MAX_DEPTH = 64;
    private const int MAX_PASSAGES = 256;
    private const int MAX_EVIDENCE_BYTES = 524_288;
    private const int MAX_STEPS = 32_768;
    private const int MAX_NANOSECONDS = 250_000_000;

    /** @var list<SourcePassage> */
    private array $passages = [];

    private int $bytes = 2;
    private int $steps = 0;
    private bool $limited = false;
    private bool $stopped = false;
    private readonly int $deadline;

    public function __construct()
    {
        $this->deadline = hrtime(true) + self::MAX_NANOSECONDS;
    }

    public function markLimited(): void
    {
        $this->limited = true;
    }

    /** Charge lines, tokens and tree visits before processing them. */
    public function advance(int $cost = 1): bool
    {
        $this->steps += $cost;
        if ($this->stopped || $this->steps > self::MAX_STEPS || hrtime(true) >= $this->deadline) {
            $this->stopped = true;
            $this->limited = true;

            return false;
        }

        return true;
    }

    public function add(SourcePassage $passage): void
    {
        if (!$this->advance()) {
            return;
        }

        $size = strlen(json_encode($passage, JSON_THROW_ON_ERROR)) + ($this->passages === [] ? 0 : 1);
        if (count($this->passages) >= self::MAX_PASSAGES || $this->bytes + $size > self::MAX_EVIDENCE_BYTES) {
            $this->limited = true;
            $this->stopped = true;

            return;
        }

        $this->passages[] = $passage;
        $this->bytes += $size;
    }

    public function result(): PassageExtraction
    {
        $outcome = match (true) {
            $this->limited => ExtractionOutcome::Limited,
            $this->passages === [] => ExtractionOutcome::Empty,
            default => ExtractionOutcome::Complete,
        };

        return new PassageExtraction($outcome, $this->passages);
    }
}
