<?php

declare(strict_types=1);

namespace Tests\Unit\PageCatalog;

use App\Application\PageCatalog\MarkdownRenderComplexity;
use App\Domain\DomainRuleViolation;
use Tests\TestCase;

final class MarkdownRenderComplexityTest extends TestCase
{
    public function test_pathological_inline_delimiter_volume_is_rejected_before_commonmark_runs(): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('safe rendering complexity');

        app(MarkdownRenderComplexity::class)->ensureSafe(str_repeat('[[a]]', 12_501));
    }

    public function test_large_plain_prose_remains_renderable(): void
    {
        app(MarkdownRenderComplexity::class)->ensureSafe(str_repeat('ordinary prose ', 100_000));

        $this->addToAssertionCount(1);
    }
}
