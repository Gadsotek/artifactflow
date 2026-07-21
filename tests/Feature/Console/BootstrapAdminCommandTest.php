<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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

    public function test_bootstrap_admin_reads_a_password_from_a_one_shot_secret_file(): void
    {
        $secretFile = storage_path('framework/testing/bootstrap-password-' . Str::random(12));
        file_put_contents($secretFile, "password from secret file\n");
        putenv('ARTIFACTFLOW_ADMIN_PASSWORD_FILE=' . $secretFile);

        try {
            $this->runConsoleCommand('artifactflow:bootstrap-admin', [
                '--name' => 'File Bootstrap Admin',
                '--email' => 'file-bootstrap-admin@example.test',
            ])->assertExitCode(0);
        } finally {
            putenv('ARTIFACTFLOW_ADMIN_PASSWORD_FILE');
            unlink($secretFile);
        }

        $admin = User::query()->where('email', 'file-bootstrap-admin@example.test')->sole();
        $this->assertTrue(Hash::check('password from secret file', $admin->password));
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
