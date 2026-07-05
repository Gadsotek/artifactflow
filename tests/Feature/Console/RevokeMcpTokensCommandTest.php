<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpAccessTokenRevoker;
use App\Models\McpAccessToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class RevokeMcpTokensCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_revoke_requires_a_uid_or_an_email(): void
    {
        $this->runConsoleCommand('artifactflow:mcp-token-revoke')
            ->expectsOutputToContain('Provide --uid to revoke one token or --email to revoke all tokens for a principal.')
            ->assertExitCode(1);
    }

    public function test_token_revoke_rejects_an_unknown_principal_email(): void
    {
        $this->runConsoleCommand('artifactflow:mcp-token-revoke', [
            '--email' => 'nobody@example.test',
        ])
            ->expectsOutputToContain('User does not exist.')
            ->assertExitCode(1);
    }

    public function test_token_revoke_reports_when_no_tokens_match(): void
    {
        User::query()->create([
            'name' => 'Tokenless User',
            'email' => 'tokenless@example.test',
            'password' => Hash::make('correct horse battery staple'),
        ]);

        $this->runConsoleCommand('artifactflow:mcp-token-revoke', [
            '--email' => 'tokenless@example.test',
        ])
            ->expectsOutputToContain('No matching MCP tokens.')
            ->assertExitCode(1);
    }

    public function test_token_revoke_by_email_revokes_active_tokens_and_skips_already_revoked_ones(): void
    {
        $workspace = $this->createWorkspace();

        $this->assertSame(0, Artisan::call('artifactflow:mcp-token-create', [
            '--email' => 'cli-agent@example.test',
            '--workspace' => [$workspace->uid],
            '--scope' => [McpAccessTokenIssuer::SCOPE_SEARCH],
        ]));
        $this->assertSame(0, Artisan::call('artifactflow:mcp-token-create', [
            '--email' => 'cli-agent@example.test',
            '--workspace' => [$workspace->uid],
            '--scope' => [McpAccessTokenIssuer::SCOPE_SEARCH],
        ]));

        $tokens = McpAccessToken::query()->orderBy('created_at')->get();
        $this->assertCount(2, $tokens);
        $alreadyRevoked = $tokens->first();
        $this->assertInstanceOf(McpAccessToken::class, $alreadyRevoked);
        app(McpAccessTokenRevoker::class)->revoke($alreadyRevoked, null, 'cli');

        $this->runConsoleCommand('artifactflow:mcp-token-revoke', [
            '--email' => 'cli-agent@example.test',
        ])
            ->expectsOutputToContain('Revoked 1 MCP token(s).')
            ->assertExitCode(0);

        foreach (McpAccessToken::query()->get() as $token) {
            $this->assertNotNull($token->revoked_at);
        }
    }

    private function createWorkspace(): Workspace
    {
        $owner = User::query()->create([
            'name' => 'Workspace Owner',
            'email' => 'workspace-owner@example.test',
            'password' => Hash::make('correct horse battery staple'),
        ]);

        return app(CreateSharedWorkspace::class)->handle($owner, 'CLI Team');
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
