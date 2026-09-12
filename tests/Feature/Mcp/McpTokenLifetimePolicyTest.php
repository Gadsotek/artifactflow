<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Application\Administration\InstallationLimitSettings;
use App\Application\Administration\InstallationLimitValues;
use App\Application\Administration\UpdateInstallationLimits;
use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Domain\DomainRuleViolation;
use App\Http\Middleware\RequireRecentSystemAdminTwoFactorConfirmation;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\InstallationSettings;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class McpTokenLifetimePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_allow_one_year_write_tokens_with_audited_persistent_settings(): void
    {
        $admin = $this->user(true);
        $this->savePolicy($admin, 365, 365);

        $this->assertDatabaseHas('installation_settings', [
            'mcp_read_token_max_ttl_days' => 365,
            'mcp_write_token_max_ttl_days' => 365,
            'updated_by_user_uid' => $admin->uid,
        ]);
        $event = DomainEvent::query()->where('event_type', 'installation.limits.updated')->sole();
        $audit = AuditEntry::query()->where('action', 'installation.limits.updated')->sole();
        $this->assertSame(365, $event->payload['mcp_write_token_max_ttl_days']);
        $this->assertSame(365, $audit->metadata['mcp_write_token_max_ttl_days']);
        $this->assertSame(365, (new InstallationLimitSettings())->current()->mcpWriteTokenMaxTtlDays);

        $this->actingAs($admin)->get(route('admin.settings.edit'))
            ->assertOk()->assertSee('MCP token lifetimes')->assertSee('Write-capable tokens');
        $this->actingAs($admin)->get(route('settings.mcp-tokens.index'))
            ->assertOk()->assertSee('Read-only: up to 365 days. Write-capable: up to 365 days.');

        $this->travelTo(now()->startOfSecond());
        $request = [
            'name' => 'Annual integration',
            'scopes' => McpAccessTokenIssuer::allowedScopes(),
            'all_workspaces' => '1',
            'expires_in_days' => 365,
            'password' => 'correct horse battery staple',
            'code' => (new Google2FA())->getCurrentOtp('JBSWY3DPEHPK3PXP'),
        ];
        $this->actingAs($admin)->post(route('settings.mcp-tokens.store'), array_replace($request, [
            'password' => 'wrong password',
        ]))->assertSessionHasErrors('password');
        $this->actingAs($admin)->post(route('settings.mcp-tokens.store'), array_replace($request, [
            'code' => 'invalid',
        ]))->assertSessionHasErrors('code');
        $this->assertDatabaseCount('mcp_access_tokens', 0);
        $this->actingAs($admin)->post(route('settings.mcp-tokens.store'), $request)->assertOk();

        $token = McpAccessToken::query()->sole();
        $this->assertTrue($token->expires_at->equalTo(now()->addDays(365)));
        $this->assertNull($token->workspaceUids());
    }

    public function test_non_admin_cannot_change_the_policy_through_http_or_application_handler(): void
    {
        $user = $this->user();
        $this->actingAs($user)->put(route('admin.settings.update'), $this->payload(365, 365))
            ->assertForbidden();
        $this->assertDatabaseCount('installation_settings', 0);

        $this->expectException(AuthorizationException::class);
        app(UpdateInstallationLimits::class)->handle($user, app(InstallationLimitSettings::class)->current());
    }

    public function test_admin_policy_changes_require_recent_two_factor_confirmation(): void
    {
        $this->actingAs($this->user(true))
            ->put(route('admin.settings.update'), $this->payload(365, 365))
            ->assertRedirect();
        $this->assertDatabaseCount('installation_settings', 0);
    }

    #[DataProvider('invalidLimits')]
    public function test_http_rejects_invalid_policy_limits(mixed $value): void
    {
        $this->actingAs($this->user(true))
            ->withSession([RequireRecentSystemAdminTwoFactorConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->put(route('admin.settings.update'), array_replace($this->payload(365, 90), [
                'mcp_read_token_max_ttl_days' => $value,
                'mcp_write_token_max_ttl_days' => $value,
            ]))
            ->assertSessionHasErrors(['mcp_read_token_max_ttl_days', 'mcp_write_token_max_ttl_days']);
        $this->assertDatabaseCount('installation_settings', 0);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidLimits(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'over year' => [366], 'fraction' => [1.5],
            'text' => ['forever'], 'null' => [null], 'array' => [[365]]];
    }

    #[DataProvider('scopeLimits')]
    public function test_issuer_enforces_configured_limit_for_every_scope(string $scope, int $maximum): void
    {
        $admin = $this->user(true);
        $this->savePolicy($admin, 14, 7);
        $this->travelTo(now()->startOfSecond());
        $issuer = app(McpAccessTokenIssuer::class);
        $token = $issuer->issue($admin, 'At boundary', [$scope], now()->addDays($maximum));
        $this->assertTrue($token->accessToken->expires_at->equalTo(now()->addDays($maximum)));

        try {
            $issuer->issue($admin, 'Over boundary', [$scope], now()->addDays($maximum)->addSecond());
            $this->fail('A token exceeded the configured expiry boundary.');
        } catch (DomainRuleViolation $exception) {
            $this->assertStringContainsString("within {$maximum} days", $exception->getMessage());
        }
        $this->assertDatabaseCount('mcp_access_tokens', 1);
    }

    /** @return array<string, array{string, int}> */
    public static function scopeLimits(): array
    {
        return ['read' => ['mcp:read', 14], 'search' => ['mcp:search', 14],
            'create' => ['mcp:create', 7], 'update' => ['mcp:update', 7],
            'organize' => ['mcp:organize', 7], 'upload' => ['mcp:upload', 7], 'share' => ['mcp:share', 7]];
    }

    public function test_http_reports_configured_expiry_errors_before_consuming_totp(): void
    {
        $admin = $this->user(true);
        $this->savePolicy($admin, 7, 1);
        $form = $this->actingAs($admin)->get(route('settings.mcp-tokens.index'))->assertOk();
        $form->assertSee('Read-only: up to 7 days. Write-capable: up to 1 day.');
        $form->assertSee('max="7" value="7"', false);
        $code = (new Google2FA())->getCurrentOtp('JBSWY3DPEHPK3PXP');
        foreach (['mcp:read' => 8, 'mcp:update' => 2] as $scope => $days) {
            $this->actingAs($admin)->post(route('settings.mcp-tokens.store'), [
                'scopes' => [$scope], 'all_workspaces' => '1', 'expires_in_days' => $days,
                'password' => 'correct horse battery staple', 'code' => $code,
            ])->assertSessionHasErrors('expires_in_days');
        }
        $this->assertDatabaseCount('mcp_access_tokens', 0);
        $this->actingAs($admin)->post(route('settings.mcp-tokens.store'), [
            'scopes' => ['mcp:update'], 'all_workspaces' => '1', 'expires_in_days' => 1,
            'password' => 'correct horse battery staple', 'code' => $code,
        ])->assertOk();
        $this->assertDatabaseCount('mcp_access_tokens', 1);
    }

    public function test_cli_obeys_policy_and_failed_issuance_rolls_back_the_service_account(): void
    {
        $admin = $this->user(true);
        $this->savePolicy($admin, 365, 365);
        $workspace = app(CreateSharedWorkspace::class)->handle($admin, 'CLI integration');
        $arguments = ['--email' => 'annual-agent@example.test', '--workspace' => [$workspace->uid],
            '--scope' => ['mcp:create', 'mcp:read'], '--ttl-days' => '365'];
        $this->assertSame(0, Artisan::call('artifactflow:mcp-token-create', $arguments));
        $this->assertDatabaseCount('mcp_access_tokens', 1);

        $this->savePolicy($admin, 365, 90);
        $arguments['--email'] = 'rejected-agent@example.test';
        $this->assertSame(1, Artisan::call('artifactflow:mcp-token-create', $arguments));
        $this->assertStringContainsString('within 90 days', Artisan::output());
        $this->assertDatabaseMissing('users', ['email' => 'rejected-agent@example.test']);
        $this->assertDatabaseCount('mcp_access_tokens', 1);
    }

    public function test_lowering_policy_preserves_existing_expiry_and_omitted_fields_preserve_settings(): void
    {
        $admin = $this->user(true);
        $this->savePolicy($admin, 365, 365);
        $issued = app(McpAccessTokenIssuer::class)->issue($admin, 'Existing', ['mcp:update'], now()->addDays(365));
        $expiry = $issued->accessToken->expires_at->toISOString();
        $this->savePolicy($admin, 14, 7);
        $payload = $this->payload(14, 7);
        unset($payload['mcp_read_token_max_ttl_days'], $payload['mcp_write_token_max_ttl_days']);
        $this->actingAs($admin)->put(route('admin.settings.update'), $payload)->assertSessionHasNoErrors();
        $this->assertSame(7, (new InstallationLimitSettings())->current()->mcpWriteTokenMaxTtlDays);
        $this->assertSame(14, (new InstallationLimitSettings())->current()->mcpReadTokenMaxTtlDays);
        $this->assertSame($expiry, $issued->accessToken->refresh()->expires_at->toISOString());
        $this->assertNull($issued->accessToken->revoked_at);
    }

    #[DataProvider('invalidDomainLimits')]
    public function test_application_values_reject_invalid_lifetimes(int $read, int $write): void
    {
        $this->expectException(DomainRuleViolation::class);
        new InstallationLimitValues(
            32,
            64,
            64,
            1000,
            1000,
            10,
            10,
            mcpReadTokenMaxTtlDays: $read,
            mcpWriteTokenMaxTtlDays: $write
        );
    }

    #[DataProvider('invalidDomainLimits')]
    public function test_database_rejects_invalid_lifetimes(int $read, int $write): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('installation_settings_mcp_token_ttl_check');
        InstallationSettings::query()->forceCreate(array_replace($this->payload(365, 90), [
            'scope' => InstallationSettings::SCOPE_INSTALLATION,
            'mcp_read_token_max_ttl_days' => $read, 'mcp_write_token_max_ttl_days' => $write,
        ]));
    }

    /** @return array<string, array{int, int}> */
    public static function invalidDomainLimits(): array
    {
        return ['read zero' => [0, 90], 'read over year' => [366, 90],
            'write zero' => [365, 0], 'write over year' => [365, 366]];
    }

    public function test_migration_preserves_settings_and_tokens_and_backfills_original_limits(): void
    {
        $admin = $this->user(true);
        $this->savePolicy($admin, 365, 365);
        $token = app(McpAccessTokenIssuer::class)->issue($admin, 'Retained', ['mcp:update'], now()->addDays(365));
        $migration = require database_path('migrations/2026_09_10_000002_add_mcp_token_lifetime_settings.php');
        $this->assertInstanceOf(Migration::class, $migration);
        $this->assertTrue(method_exists($migration, 'down'));
        $this->assertTrue(method_exists($migration, 'up'));
        $migration->down();
        $migration->up();

        $this->assertDatabaseHas('installation_settings', [
            'max_markdown_bytes' => 32, 'updated_by_user_uid' => $admin->uid,
            'mcp_read_token_max_ttl_days' => 365, 'mcp_write_token_max_ttl_days' => 90,
        ]);
        $this->assertDatabaseCount('mcp_access_tokens', 1);
        $this->assertTrue($token->accessToken->refresh()->expires_at->isFuture());
        $this->assertNull($token->accessToken->revoked_at);
    }

    private function user(bool $admin = false): User
    {
        return User::query()->forceCreate([
            'name' => 'Policy user', 'email' => 'policy-user@example.test',
            'password' => Hash::make('correct horse battery staple'), 'is_system_admin' => $admin,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now(),
        ]);
    }

    private function savePolicy(User $admin, int $read, int $write): void
    {
        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminTwoFactorConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->put(route('admin.settings.update'), $this->payload($read, $write))
            ->assertRedirect(route('admin.settings.edit'))->assertSessionHasNoErrors();
    }

    /** @return array<string, int> */
    private function payload(int $read, int $write): array
    {
        return ['max_markdown_bytes' => 32, 'max_html_bytes' => 64, 'artifact_max_bytes' => 64,
            'max_workspace_storage_bytes' => 1000, 'max_page_storage_bytes' => 1000,
            'max_page_versions' => 10, 'max_tags_per_page' => 10,
            'mcp_read_token_max_ttl_days' => $read, 'mcp_write_token_max_ttl_days' => $write];
    }
}
