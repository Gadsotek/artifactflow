<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp;

use App\Application\Mcp\McpProvenanceArguments;
use App\Application\Mcp\McpToolArguments;
use App\Domain\DomainRuleViolation;
use PHPUnit\Framework\TestCase;

final class McpProvenanceArgumentsTest extends TestCase
{
    public function test_it_accepts_rfc3339_timestamps_with_optional_fractional_seconds(): void
    {
        foreach ([
            '2026-08-01T13:42:00Z' => '2026-08-01T13:42:00.000000+00:00',
            '2026-08-01T13:42:00.1Z' => '2026-08-01T13:42:00.100000+00:00',
            '2026-08-01T13:42:00.123Z' => '2026-08-01T13:42:00.123000+00:00',
            '2026-08-01T13:42:00.123456+02:30' => '2026-08-01T13:42:00.123456+02:30',
            '2026-08-01T13:42:00.123456+23:59' => '2026-08-01T13:42:00.123456+23:59',
        ] as $timestamp => $expected) {
            $provenance = (new McpProvenanceArguments())->fromArguments($this->arguments($timestamp));

            self::assertNotNull($provenance);
            self::assertSame(
                $expected,
                $provenance->producers[0]->generatedAt?->format('Y-m-d\TH:i:s.uP'),
            );
        }
    }

    public function test_fractional_seconds_do_not_bypass_calendar_overflow_validation(): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('Provenance generated_at must be an exact RFC 3339 timestamp.');

        (new McpProvenanceArguments())->fromArguments(
            $this->arguments('2026-02-30T10:00:00.123Z'),
        );
    }

    public function test_it_rejects_rfc3339_offsets_outside_the_valid_range(): void
    {
        foreach ([
            '2026-08-01T13:42:00+24:00',
            '2026-08-01T13:42:00-24:00',
            '2026-08-01T13:42:00+23:60',
            '2026-08-01T13:42:00-23:60',
        ] as $timestamp) {
            try {
                (new McpProvenanceArguments())->fromArguments($this->arguments($timestamp));
                self::fail(sprintf('Expected [%s] to be rejected.', $timestamp));
            } catch (DomainRuleViolation $exception) {
                self::assertSame(
                    'Provenance generated_at must be an exact RFC 3339 timestamp.',
                    $exception->getMessage(),
                );
            }
        }
    }

    private function arguments(string $generatedAt): McpToolArguments
    {
        return McpToolArguments::fromValue([
            'provenance' => [
                'producers' => [[
                    'kind' => 'ai',
                    'provider' => 'anthropic',
                    'model_id' => 'claude-opus',
                    'generated_at' => $generatedAt,
                ]],
            ],
        ], 'arguments');
    }
}
