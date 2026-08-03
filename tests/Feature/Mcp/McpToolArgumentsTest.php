<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Application\Mcp\McpToolArguments;
use App\Domain\DomainRuleViolation;
use PHPUnit\Framework\TestCase;

final class McpToolArgumentsTest extends TestCase
{
    public function test_string_list_applies_its_maximum_after_normalization(): void
    {
        $arguments = McpToolArguments::fromValue([
            'values' => [' tag ', '', '   ', 'tag'],
        ], 'arguments');

        $this->assertSame(['tag'], $arguments->stringList('values', 1));
    }

    public function test_string_list_rejects_an_effective_list_above_its_maximum(): void
    {
        $arguments = McpToolArguments::fromValue([
            'values' => ['tag-a', 'tag-b'],
        ], 'arguments');

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage(
            'Argument [values] must contain at most 1 distinct non-blank strings.',
        );

        $arguments->stringList('values', 1);
    }
}
