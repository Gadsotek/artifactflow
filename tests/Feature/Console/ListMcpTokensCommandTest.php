<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class ListMcpTokensCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_list_requires_a_principal_email(): void
    {
        $this->runConsoleCommand('artifactflow:mcp-token-list')
            ->expectsOutputToContain('A principal email is required.')
            ->assertExitCode(1);
    }

    public function test_token_list_rejects_an_unknown_principal_email(): void
    {
        $this->runConsoleCommand('artifactflow:mcp-token-list', [
            '--email' => 'nobody@example.test',
        ])
            ->expectsOutputToContain('User does not exist.')
            ->assertExitCode(1);
    }

    public function test_token_list_reports_a_principal_without_tokens_as_success(): void
    {
        User::query()->create([
            'name' => 'Tokenless User',
            'email' => 'tokenless@example.test',
            'password' => Hash::make('correct horse battery staple'),
        ]);

        $this->runConsoleCommand('artifactflow:mcp-token-list', [
            '--email' => 'tokenless@example.test',
        ])
            ->expectsOutputToContain('No MCP tokens for tokenless@example.test.')
            ->assertExitCode(0);
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
