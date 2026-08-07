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
            '--cases=49',
        ]);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        $corpus = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($corpus);
        $this->assertSame(3_589_231_737, $corpus['seed'] ?? null);
        $this->assertSame(49, $corpus['requestedCases'] ?? null);

        $cases = $corpus['cases'] ?? null;

        $this->assertIsArray($cases);
        $this->assertCount(49, $cases);

        $rejectedCase = $cases[48] ?? null;

        $this->assertIsArray($rejectedCase);
        $this->assertSame('generated/048/family-3', $rejectedCase['id'] ?? null);
        $this->assertSame('rejected', $rejectedCase['outcome'] ?? null);
        $this->assertArrayHasKey('hardened', $rejectedCase);
        $this->assertNull($rejectedCase['hardened']);
    }
}
