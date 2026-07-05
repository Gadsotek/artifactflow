<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class VerifyArtifactIntegrityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_verify_artifacts_rejects_a_zero_sample_size(): void
    {
        $this->runConsoleCommand('artifactflow:verify-artifacts', [
            '--sample' => '0',
        ])
            ->expectsOutputToContain('Artifact verification sample size must be positive.')
            ->assertExitCode(1);
    }

    public function test_verify_artifacts_rejects_a_non_numeric_sample_size(): void
    {
        $this->runConsoleCommand('artifactflow:verify-artifacts', [
            '--sample' => 'not-a-number',
        ])
            ->expectsOutputToContain('Artifact verification sample size must be positive.')
            ->assertExitCode(1);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function runConsoleCommand(string $command, array $parameters = []): PendingCommand
    {
        $pendingCommand = $this->artisan($command, $parameters);
        $this->assertInstanceOf(PendingCommand::class, $pendingCommand);

        return $pendingCommand;
    }
}
