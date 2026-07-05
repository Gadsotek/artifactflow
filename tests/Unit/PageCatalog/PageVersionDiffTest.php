<?php

declare(strict_types=1);

namespace Tests\Unit\PageCatalog;

use App\Application\PageCatalog\PageVersionDiff;
use Tests\TestCase;

final class PageVersionDiffTest extends TestCase
{
    public function test_it_builds_a_line_diff_with_stable_line_numbers(): void
    {
        $diff = app(PageVersionDiff::class)->compare(
            "alpha\nsame\nold\nomega",
            "alpha\nsame\nnew\nomega",
        );

        $this->assertFalse($diff->tooLarge);
        $this->assertSame(1, $diff->addedLines);
        $this->assertSame(1, $diff->removedLines);
        $this->assertSame(
            ['equal', 'equal', 'removed', 'added', 'equal'],
            array_map(static fn ($line): string => $line->kind->value, $diff->lines),
        );
        $this->assertSame([1, 2, 3, null, 4], array_column($diff->lines, 'oldLineNumber'));
        $this->assertSame([1, 2, null, 3, 4], array_column($diff->lines, 'newLineNumber'));
    }

    public function test_it_collapses_long_unchanged_runs_around_changes(): void
    {
        $before = implode("\n", [...array_map(static fn (int $line): string => 'same ' . $line, range(1, 20)), 'old']);
        $after = implode("\n", [...array_map(static fn (int $line): string => 'same ' . $line, range(1, 20)), 'new']);
        $diff = app(PageVersionDiff::class)->compare($before, $after);

        $this->assertContains('omitted', array_map(static fn ($line): string => $line->kind->value, $diff->lines));
        $this->assertSame(1, $diff->addedLines);
        $this->assertSame(1, $diff->removedLines);
    }

    public function test_it_does_not_render_far_trailing_context_after_the_final_change(): void
    {
        $unchangedTail = array_map(static fn (int $line): string => 'same ' . $line, range(1, 20));
        $diff = app(PageVersionDiff::class)->compare(
            implode("\n", ['old', ...$unchangedTail]),
            implode("\n", ['new', ...$unchangedTail]),
        );

        $this->assertSame(
            ['removed', 'added', 'equal', 'equal', 'equal', 'omitted'],
            array_map(static fn ($line): string => $line->kind->value, $diff->lines),
        );
        $this->assertSame(17, $diff->lines[5]->omittedLineCount);
    }
}
