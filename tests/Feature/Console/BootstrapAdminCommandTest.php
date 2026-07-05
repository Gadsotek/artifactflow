<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class BootstrapAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_admin_falls_back_to_the_configured_one_shot_password(): void
    {
        config(['app.bootstrap_admin_password' => 'configured one-shot password']);

        $this->runConsoleCommand('artifactflow:bootstrap-admin', [
            '--name' => 'Bootstrap Admin',
            '--email' => 'bootstrap-admin@example.test',
        ])
            ->expectsOutputToContain('System admin ready: bootstrap-admin@example.test')
            ->assertExitCode(0);

        $admin = User::query()->where('email', 'bootstrap-admin@example.test')->sole();
        $this->assertTrue($admin->is_system_admin);
    }

    public function test_bootstrap_admin_accepts_an_explicit_password_option(): void
    {
        config(['app.bootstrap_admin_password' => '']);

        $this->runConsoleCommand('artifactflow:bootstrap-admin', [
            '--name' => 'Bootstrap Admin',
            '--email' => 'bootstrap-admin@example.test',
            '--password' => 'correct horse battery staple',
        ])
            ->expectsOutputToContain('System admin ready: bootstrap-admin@example.test')
            ->assertExitCode(0);

        $admin = User::query()->where('email', 'bootstrap-admin@example.test')->sole();
        $this->assertTrue($admin->is_system_admin);
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
