<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PageVersionDiffLineKind;

final class PageVersionDiff
{
    private const int CONTEXT_LINES = 3;

    private const int MAX_INPUT_BYTES = 1_000_000;

    private const int MAX_INPUT_LINES = 20_000;

    private const int MAX_RENDERED_LINES = 10_000;

    public function compare(string $before, string $after): PageVersionDiffResult
    {
        if (strlen($before) + strlen($after) > self::MAX_INPUT_BYTES) {
            return new PageVersionDiffResult([], 0, 0, true);
        }

        $beforeLines = $this->lines($before);
        $afterLines = $this->lines($after);

        if (count($beforeLines) > self::MAX_INPUT_LINES || count($afterLines) > self::MAX_INPUT_LINES) {
            return new PageVersionDiffResult([], 0, 0, true);
        }

        /** @var list<array{kind: PageVersionDiffLineKind, content: string}> $operations */
        $operations = [];
        $this->diffRange($beforeLines, $afterLines, 0, count($beforeLines), 0, count($afterLines), $operations);

        if (count($operations) > self::MAX_RENDERED_LINES) {
            return new PageVersionDiffResult([], 0, 0, true);
        }

        $lines = $this->numberedLines($operations);
        $addedLines = count(array_filter(
            $lines,
            static fn (PageVersionDiffLine $line): bool => $line->kind === PageVersionDiffLineKind::Added,
        ));
        $removedLines = count(array_filter(
            $lines,
            static fn (PageVersionDiffLine $line): bool => $line->kind === PageVersionDiffLineKind::Removed,
        ));

        return new PageVersionDiffResult(
            $this->collapseUnchangedLines($lines),
            $addedLines,
            $removedLines,
            false,
        );
    }

    /**
     * Patience diff keeps comparison bounded for page-sized source documents. It
     * anchors on lines unique in both ranges and falls back to a replacement for
     * ambiguous regions, avoiding the quadratic memory profile of an LCS matrix.
     *
     * @param list<string> $before
     * @param list<string> $after
     * @param list<array{kind: PageVersionDiffLineKind, content: string}> $operations
     */
    private function diffRange(
        array $before,
        array $after,
        int $beforeStart,
        int $beforeEnd,
        int $afterStart,
        int $afterEnd,
        array &$operations,
    ): void {
        while (
            $beforeStart < $beforeEnd
            && $afterStart < $afterEnd
            && $before[$beforeStart] === $after[$afterStart]
        ) {
            $operations[] = ['kind' => PageVersionDiffLineKind::Equal, 'content' => $before[$beforeStart]];
            $beforeStart++;
            $afterStart++;
        }

        $suffix = [];

        while (
            $beforeStart < $beforeEnd
            && $afterStart < $afterEnd
            && $before[$beforeEnd - 1] === $after[$afterEnd - 1]
        ) {
            $suffix[] = $before[$beforeEnd - 1];
            $beforeEnd--;
            $afterEnd--;
        }

        if ($beforeStart === $beforeEnd || $afterStart === $afterEnd) {
            $this->appendReplacement(
                $before,
                $after,
                $beforeStart,
                $beforeEnd,
                $afterStart,
                $afterEnd,
                $operations,
            );
            $this->appendSuffix($suffix, $operations);

            return;
        }

        $anchors = $this->anchors($before, $after, $beforeStart, $beforeEnd, $afterStart, $afterEnd);

        if ($anchors === []) {
            $this->appendReplacement(
                $before,
                $after,
                $beforeStart,
                $beforeEnd,
                $afterStart,
                $afterEnd,
                $operations,
            );
            $this->appendSuffix($suffix, $operations);

            return;
        }

        $previousBefore = $beforeStart;
        $previousAfter = $afterStart;

        foreach ($anchors as $anchor) {
            $this->diffRange(
                $before,
                $after,
                $previousBefore,
                $anchor['before'],
                $previousAfter,
                $anchor['after'],
                $operations,
            );
            $operations[] = [
                'kind' => PageVersionDiffLineKind::Equal,
                'content' => $before[$anchor['before']],
            ];
            $previousBefore = $anchor['before'] + 1;
            $previousAfter = $anchor['after'] + 1;
        }

        $this->diffRange(
            $before,
            $after,
            $previousBefore,
            $beforeEnd,
            $previousAfter,
            $afterEnd,
            $operations,
        );
        $this->appendSuffix($suffix, $operations);
    }

    /**
     * @param list<string> $before
     * @param list<string> $after
     * @return list<array{before: int, after: int}>
     */
    private function anchors(
        array $before,
        array $after,
        int $beforeStart,
        int $beforeEnd,
        int $afterStart,
        int $afterEnd,
    ): array {
        $beforePositions = $this->uniquePositions($before, $beforeStart, $beforeEnd);
        $afterPositions = $this->uniquePositions($after, $afterStart, $afterEnd);
        $pairs = [];

        foreach ($beforePositions as $line => $beforePosition) {
            $afterPosition = $afterPositions[$line] ?? null;

            if (is_int($beforePosition) && is_int($afterPosition)) {
                $pairs[] = ['before' => $beforePosition, 'after' => $afterPosition];
            }
        }

        usort($pairs, static fn (array $left, array $right): int => $left['before'] <=> $right['before']);

        return $this->longestIncreasingAfterPositions($pairs);
    }

    /**
     * @param list<string> $lines
     * @return array<string, int|null>
     */
    private function uniquePositions(array $lines, int $start, int $end): array
    {
        $positions = [];

        for ($position = $start; $position < $end; $position++) {
            $line = $lines[$position];

            if (array_key_exists($line, $positions)) {
                $positions[$line] = null;
            } else {
                $positions[$line] = $position;
            }
        }

        return $positions;
    }

    /**
     * @param list<array{before: int, after: int}> $pairs
     * @return list<array{before: int, after: int}>
     */
    private function longestIncreasingAfterPositions(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        $tailAfter = [];
        $tailPairIndexes = [];
        $previousIndexes = array_fill(0, count($pairs), null);

        foreach ($pairs as $pairIndex => $pair) {
            $low = 0;
            $high = count($tailAfter);

            while ($low < $high) {
                $middle = intdiv($low + $high, 2);

                if ($tailAfter[$middle] < $pair['after']) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }

            if ($low > 0) {
                $previousIndexes[$pairIndex] = $tailPairIndexes[$low - 1];
            }

            $tailAfter[$low] = $pair['after'];
            $tailPairIndexes[$low] = $pairIndex;
        }

        $result = [];
        $pairIndex = $tailPairIndexes[count($tailPairIndexes) - 1];

        while (is_int($pairIndex)) {
            $result[] = $pairs[$pairIndex];
            $pairIndex = $previousIndexes[$pairIndex];
        }

        return array_reverse($result);
    }

    /**
     * @param list<string> $before
     * @param list<string> $after
     * @param list<array{kind: PageVersionDiffLineKind, content: string}> $operations
     */
    private function appendReplacement(
        array $before,
        array $after,
        int $beforeStart,
        int $beforeEnd,
        int $afterStart,
        int $afterEnd,
        array &$operations,
    ): void {
        for ($position = $beforeStart; $position < $beforeEnd; $position++) {
            $operations[] = ['kind' => PageVersionDiffLineKind::Removed, 'content' => $before[$position]];
        }

        for ($position = $afterStart; $position < $afterEnd; $position++) {
            $operations[] = ['kind' => PageVersionDiffLineKind::Added, 'content' => $after[$position]];
        }
    }

    /**
     * @param list<string> $suffix
     * @param list<array{kind: PageVersionDiffLineKind, content: string}> $operations
     */
    private function appendSuffix(array $suffix, array &$operations): void
    {
        foreach (array_reverse($suffix) as $line) {
            $operations[] = ['kind' => PageVersionDiffLineKind::Equal, 'content' => $line];
        }
    }

    /**
     * @param list<array{kind: PageVersionDiffLineKind, content: string}> $operations
     * @return list<PageVersionDiffLine>
     */
    private function numberedLines(array $operations): array
    {
        $oldLineNumber = 1;
        $newLineNumber = 1;
        $lines = [];

        foreach ($operations as $operation) {
            $kind = $operation['kind'];
            $lines[] = new PageVersionDiffLine(
                kind: $kind,
                oldLineNumber: $kind === PageVersionDiffLineKind::Added ? null : $oldLineNumber,
                newLineNumber: $kind === PageVersionDiffLineKind::Removed ? null : $newLineNumber,
                content: $operation['content'],
            );

            if ($kind !== PageVersionDiffLineKind::Added) {
                $oldLineNumber++;
            }

            if ($kind !== PageVersionDiffLineKind::Removed) {
                $newLineNumber++;
            }
        }

        return $lines;
    }

    /**
     * @param list<PageVersionDiffLine> $lines
     * @return list<PageVersionDiffLine>
     */
    private function collapseUnchangedLines(array $lines): array
    {
        $collapsed = [];
        $position = 0;

        while ($position < count($lines)) {
            if ($lines[$position]->kind !== PageVersionDiffLineKind::Equal) {
                $collapsed[] = $lines[$position];
                $position++;

                continue;
            }

            $runStart = $position;

            while ($position < count($lines) && $lines[$position]->kind === PageVersionDiffLineKind::Equal) {
                $position++;
            }

            $run = array_slice($lines, $runStart, $position - $runStart);

            if (count($run) <= (self::CONTEXT_LINES * 2) + 1) {
                array_push($collapsed, ...$run);

                continue;
            }

            $leadingContext = $runStart === 0 ? [] : array_slice($run, 0, self::CONTEXT_LINES);
            $trailingContext = $position === count($lines) ? [] : array_slice($run, -self::CONTEXT_LINES);
            $omittedCount = count($run) - count($leadingContext) - count($trailingContext);

            array_push($collapsed, ...$leadingContext);
            $collapsed[] = new PageVersionDiffLine(
                PageVersionDiffLineKind::Omitted,
                null,
                null,
                '',
                $omittedCount,
            );
            array_push($collapsed, ...$trailingContext);
        }

        return $collapsed;
    }

    /**
     * @return list<string>
     */
    private function lines(string $source): array
    {
        return explode("\n", str_replace(["\r\n", "\r"], "\n", $source));
    }
}
