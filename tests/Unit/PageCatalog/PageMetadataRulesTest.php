<?php

declare(strict_types=1);

namespace Tests\Unit\PageCatalog;

use App\Application\PageCatalog\PageMetadataRules;
use App\Domain\DomainRuleViolation;
use PHPUnit\Framework\TestCase;

final class PageMetadataRulesTest extends TestCase
{
    public function test_title_rejects_a_nul_byte_the_http_rule_would_otherwise_miss(): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('Page title must not contain control characters or invalid text.');

        // MCP and CLI callers reach the application layer without the HTTP StorableText
        // rule, so the guard must live here too: a NUL byte would otherwise reach the
        // PostgreSQL text column and 500 rather than fail cleanly. The NUL sits mid-string
        // because trim() alone would strip a leading/trailing one.
        (new PageMetadataRules())->normalizeTitle("Run\0book");
    }

    public function test_description_rejects_malformed_utf8(): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('Page description must not contain control characters or invalid text.');

        (new PageMetadataRules())->normalizeDescription("Notes \xFF here");
    }

    public function test_clean_title_and_description_pass_through_unchanged(): void
    {
        $rules = new PageMetadataRules();

        $this->assertSame('Release Runbook', $rules->normalizeTitle('  Release Runbook  '));
        $this->assertSame("Line one\nLine two", $rules->normalizeDescription("Line one\nLine two"));
        $this->assertNull($rules->normalizeDescription('   '));
    }
}
