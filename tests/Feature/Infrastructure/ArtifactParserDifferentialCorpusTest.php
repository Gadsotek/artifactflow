<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use JsonException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ArtifactParserDifferentialCorpusTest extends TestCase
{
    /**
     * @throws JsonException
     */
    public function test_complexity_exhaustion_is_reported_as_a_fail_closed_rejection(): void
    {
        $process = new Process([
            PHP_BINARY,
            base_path('tests/e2e/support/artifact-parser-differential-corpus.php'),
            '--seed=3589231737',
            '--cases=50',
        ]);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        $corpus = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($corpus);
        $this->assertSame(3_589_231_737, $corpus['seed'] ?? null);
        $this->assertSame(50, $corpus['requestedCases'] ?? null);

        $cases = $corpus['cases'] ?? null;

        $this->assertIsArray($cases);
        $this->assertCount(50, $cases);

        $matchingCases = array_values(array_filter(
            $cases,
            static fn (mixed $case): bool => is_array($case)
                && ($case['id'] ?? null) === 'regression/complexity-exhaustion',
        ));

        $this->assertCount(1, $matchingCases);
        $rejectedCase = $matchingCases[0];
        $this->assertSame('regression/complexity-exhaustion', $rejectedCase['id']);
        $this->assertSame('rejected', $rejectedCase['outcome'] ?? null);
        $this->assertArrayHasKey('hardened', $rejectedCase);
        $this->assertNull($rejectedCase['hardened']);
    }
}
