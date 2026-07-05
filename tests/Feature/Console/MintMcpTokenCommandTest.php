<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class MintMcpTokenCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_create_requires_a_service_account_email(): void
    {
        $this->runConsoleCommand('artifactflow:mcp-token-create', [
            '--workspace' => ['some-workspace-uid'],
        ])
            ->expectsOutputToContain('A service-account email is required.')
            ->assertExitCode(1);

        $this->assertSame(0, McpAccessToken::query()->count());
    }

    public function test_token_create_requires_at_least_one_workspace(): void
    {
        $this->runConsoleCommand('artifactflow:mcp-token-create', [
            '--email' => 'cli-agent@example.test',
        ])
            ->expectsOutputToContain('At least one --workspace UID is required.')
            ->assertExitCode(1);

        $this->assertSame(0, McpAccessToken::query()->count());
    }

    public function test_token_create_rejects_a_zero_day_ttl(): void
    {
        $this->runConsoleCommand('artifactflow:mcp-token-create', [
            '--email' => 'cli-agent@example.test',
            '--workspace' => ['some-workspace-uid'],
            '--ttl-days' => '0',
        ])
            ->expectsOutputToContain('Token TTL must be at least one day.')
            ->assertExitCode(1);

        $this->assertSame(0, McpAccessToken::query()->count());
    }

    public function test_token_create_rejects_a_write_scope_beyond_the_max_ttl(): void
    {
        $owner = User::query()->create([
            'name' => 'Workspace Owner',
            'email' => 'workspace-owner@example.test',
            'password' => Hash::make('correct horse battery staple'),
        ]);
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'CLI Team');

        $this->runConsoleCommand('artifactflow:mcp-token-create', [
            '--email' => 'cli-agent@example.test',
            '--workspace' => [$workspace->uid],
            '--scope' => [McpAccessTokenIssuer::SCOPE_CREATE],
            '--ttl-days' => '100000',
        ])
            ->expectsOutputToContain('Write-capable MCP tokens must expire within 90 days.')
            ->assertExitCode(1);

        $this->assertSame(0, McpAccessToken::query()->count());
    }

    public function test_token_create_rejects_an_unknown_workspace(): void
    {
        $this->runConsoleCommand('artifactflow:mcp-token-create', [
            '--email' => 'cli-agent@example.test',
            '--workspace' => ['missing-workspace-uid'],
        ])
            ->expectsOutputToContain('Workspace [missing-workspace-uid] does not exist.')
            ->assertExitCode(1);

        $this->assertSame(0, McpAccessToken::query()->count());
    }

    public function test_token_create_defaults_to_the_full_editor_scope_set(): void
    {
        $owner = User::query()->create([
            'name' => 'Workspace Owner',
            'email' => 'workspace-owner@example.test',
            'password' => Hash::make('correct horse battery staple'),
        ]);
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'CLI Team');

        $this->runConsoleCommand('artifactflow:mcp-token-create', [
            '--email' => 'cli-agent@example.test',
            '--workspace' => [$workspace->uid],
        ])
            ->expectsOutputToContain('MCP service account ready: cli-agent@example.test')
            ->assertExitCode(0);

        $token = McpAccessToken::query()->sole();
        $this->assertEqualsCanonicalizing([
            McpAccessTokenIssuer::SCOPE_SEARCH,
            McpAccessTokenIssuer::SCOPE_READ,
            McpAccessTokenIssuer::SCOPE_CREATE,
            McpAccessTokenIssuer::SCOPE_UPDATE,
        ], $token->scopes);

        $serviceAccount = User::query()->where('email', 'cli-agent@example.test')->sole();
        $this->assertTrue($serviceAccount->is_service_account);
        $this->assertSame($serviceAccount->uid, $token->principal_user_uid);
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
