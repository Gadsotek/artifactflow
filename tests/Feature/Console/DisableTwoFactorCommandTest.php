<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class DisableTwoFactorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_disable_two_factor_requires_the_force_flag(): void
    {
        $this->runConsoleCommand('artifactflow:disable-2fa', [
            '--email' => 'admin@example.test',
            '--reason' => 'device lost',
        ])
            ->expectsOutputToContain('Use --force to intentionally disable two-factor authentication.')
            ->assertExitCode(1);
    }

    public function test_disable_two_factor_reports_an_unknown_user(): void
    {
        $this->runConsoleCommand('artifactflow:disable-2fa', [
            '--email' => 'nobody@example.test',
            '--force' => true,
            '--reason' => 'device lost',
        ])
            ->expectsOutputToContain('User does not exist.')
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
