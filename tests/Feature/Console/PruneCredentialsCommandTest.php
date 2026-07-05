<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\McpAccessToken;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class PruneCredentialsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'credentials.trusted_device_retention_days' => 0,
            'credentials.mcp_token_retention_days' => 30,
        ]);
    }

    public function test_it_prunes_expired_trusted_devices_but_keeps_active_ones(): void
    {
        $user = User::factory()->create();
        $this->trustedDevice($user, 'expired', now()->subDay());
        $this->trustedDevice($user, 'active', now()->addDays(30));

        $this->runConsoleCommand('artifactflow:prune-credentials')->assertSuccessful();

        $this->assertFalse(TrustedDevice::query()->where('label', 'expired')->exists());
        $this->assertTrue(TrustedDevice::query()->where('label', 'active')->exists());
    }

    public function test_it_prunes_retired_mcp_tokens_only_after_the_retention_window(): void
    {
        $user = User::factory()->create();
        $this->mcpToken($user, 'active', expiresAt: now()->addDays(10));
        $this->mcpToken($user, 'recently-revoked', expiresAt: now()->addDays(10), revokedAt: now()->subDays(5));
        $this->mcpToken($user, 'recently-expired', expiresAt: now()->subDays(5));
        $this->mcpToken($user, 'long-revoked', expiresAt: now()->addDays(10), revokedAt: now()->subDays(40));
        $this->mcpToken($user, 'long-expired', expiresAt: now()->subDays(40));

        $this->runConsoleCommand('artifactflow:prune-credentials')->assertSuccessful();

        // Live and recently retired tokens stay visible in settings history.
        $this->assertTrue($this->tokenExists('active'));
        $this->assertTrue($this->tokenExists('recently-revoked'));
        $this->assertTrue($this->tokenExists('recently-expired'));

        // Tokens retired past the window are gone.
        $this->assertFalse($this->tokenExists('long-revoked'));
        $this->assertFalse($this->tokenExists('long-expired'));
    }

    public function test_dry_run_reports_counts_without_deleting(): void
    {
        $user = User::factory()->create();
        $this->trustedDevice($user, 'expired', now()->subDay());
        $this->mcpToken($user, 'long-expired', expiresAt: now()->subDays(40));

        $this->runConsoleCommand('artifactflow:prune-credentials', ['--dry-run' => true])
            ->expectsOutputToContain('Would prune 1 expired trusted device and 1 retired MCP token.')
            ->assertSuccessful();

        $this->assertTrue(TrustedDevice::query()->where('label', 'expired')->exists());
        $this->assertTrue($this->tokenExists('long-expired'));
    }

    private function trustedDevice(User $user, string $label, \DateTimeInterface $expiresAt): void
    {
        TrustedDevice::query()->forceCreate([
            'user_uid' => $user->uid,
            'token_hash' => hash('sha256', $label . '|' . $user->uid),
            'label' => $label,
            'user_agent_summary' => 'Test browser',
            'expires_at' => $expiresAt,
            'last_used_at' => now()->subDay(),
        ]);
    }

    private function mcpToken(
        User $user,
        string $name,
        \DateTimeInterface $expiresAt,
        ?\DateTimeInterface $revokedAt = null,
    ): void {
        McpAccessToken::query()->forceCreate([
            'principal_user_uid' => $user->uid,
            'name' => $name,
            'token_hash' => hash('sha256', $name . '|' . $user->uid),
            'scopes' => ['search'],
            'workspace_uids' => null,
            'expires_at' => $expiresAt,
            'revoked_at' => $revokedAt,
        ]);
    }

    private function tokenExists(string $name): bool
    {
        return McpAccessToken::query()->where('name', $name)->exists();
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
