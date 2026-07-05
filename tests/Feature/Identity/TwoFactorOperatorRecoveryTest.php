<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\CreatePersonalWorkspaceForUser;
use App\Application\Identity\DisableTwoFactorForOperator;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\InstallationSettings;
use App\Models\McpAccessToken;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class TwoFactorOperatorRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_break_glass_clears_two_factor_and_enforcement_without_secrets(): void
    {
        $admin = $this->createLockedAdmin();
        $secret = (string) $admin->two_factor_secret;
        $recoveryCodeHashes = $admin->two_factor_recovery_codes;
        $this->assertIsArray($recoveryCodeHashes);
        $trustedDevice = TrustedDevice::query()->where('user_uid', $admin->uid)->sole();
        $trustedDeviceTokenHash = $trustedDevice->token_hash;
        $mcpToken = app(McpAccessTokenIssuer::class)->issue(
            principal: $admin,
            name: 'Break-glass token',
            scopes: [McpAccessTokenIssuer::SCOPE_SEARCH],
            expiresAt: now()->addHour(),
        )->accessToken;

        $exitCode = Artisan::call('artifactflow:disable-2fa', [
            '--email' => 'admin@example.test',
            '--force' => true,
            '--reason' => 'device lost during restore drill',
            '--clear-enforcement' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $admin->refresh();
        $this->assertNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_confirmed_at);
        $this->assertNull($admin->two_factor_recovery_codes);
        $this->assertNull($admin->two_factor_last_used_timestep);
        $this->assertFalse($admin->two_factor_required);
        $this->assertSame(0, TrustedDevice::query()->where('user_uid', $admin->uid)->count());
        $this->assertNotNull($mcpToken->refresh()->revoked_at);
        $this->assertSame(1, McpAccessToken::query()->where('principal_user_uid', $admin->uid)->count());

        $settings = InstallationSettings::query()->where('scope', InstallationSettings::SCOPE_INSTALLATION)->sole();
        $this->assertFalse($settings->two_factor_required_for_system_admins);
        $this->assertFalse($settings->two_factor_required_for_all_users);

        $event = DomainEvent::query()->where('event_type', 'user.two_factor.operator_disabled')->sole();
        $audit = AuditEntry::query()->where('action', 'user.two_factor.operator_disabled')->sole();
        $this->assertSame($admin->uid, $event->aggregate_uid);
        $this->assertSame('device lost during restore drill', $event->payload['reason']);
        $this->assertArrayNotHasKey('two_factor_secret', $event->payload);
        $this->assertArrayNotHasKey('recovery_codes', $audit->metadata);
        $this->assertSame(1, $audit->metadata['mcp_tokens_revoked']);
        $this->assertSame(
            1,
            DomainEvent::query()
                ->where('event_type', 'mcp_token.revoked')
                ->where('aggregate_uid', $mcpToken->uid)
                ->count(),
        );
        $this->assertSensitiveValuesAbsentFromSecurityRecords(array_merge(
            [$secret, 'ABCD2-EFGH3', $trustedDeviceTokenHash],
            $recoveryCodeHashes,
        ));

        $this->post('/login', [
            'email' => 'admin@example.test',
            'password' => 'correct horse battery staple',
        ])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($admin);

        $this->actingAs($admin)
            ->withSession(['auth.system_admin_password_confirmed_at' => now()->getTimestamp()])
            ->get('/admin/users')
            ->assertOk();
    }

    public function test_break_glass_handler_refuses_http_context_and_no_network_route_exists(): void
    {
        $admin = $this->createLockedAdmin('http-admin@example.test');

        $this->expectException(\RuntimeException::class);
        app(DisableTwoFactorForOperator::class)->handle(
            email: $admin->email,
            reason: 'forged web request',
            clearEnforcement: false,
            force: true,
            invokedFromHttpLifecycle: true,
        );
    }

    public function test_diagnose_two_factor_reports_unreadable_secrets_without_sensitive_values(): void
    {
        $readable = $this->createLockedAdmin('readable@example.test');
        $unreadable = $this->createLockedAdmin('unreadable@example.test');
        DB::table('users')
            ->where('uid', $unreadable->uid)
            ->update(['two_factor_secret' => 'not-a-valid-encrypted-secret']);

        $exitCode = Artisan::call('artifactflow:diagnose-2fa');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('checked=2', $output);
        $this->assertStringContainsString('readable=1', $output);
        $this->assertStringContainsString('unreadable=1', $output);
        $this->assertStringNotContainsString($readable->email, $output);
        $this->assertStringNotContainsString($unreadable->email, $output);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $output);
        $this->assertStringNotContainsString('not-a-valid-encrypted-secret', $output);

        $jsonExitCode = Artisan::call('artifactflow:diagnose-2fa', ['--json' => true]);
        $jsonOutput = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $jsonExitCode);
        $this->assertSame([
            'checked' => 2,
            'readable' => 1,
            'unreadable' => 1,
        ], $jsonOutput);
    }

    private function createLockedAdmin(string $email = 'admin@example.test'): User
    {
        $admin = User::query()->create([
            'name' => 'System Admin',
            'email' => $email,
            'password' => Hash::make('correct horse battery staple'),
        ]);
        $admin->forceFill([
            'is_system_admin' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [Hash::make('ABCD2-EFGH3')],
            'two_factor_last_used_timestep' => 123,
            'two_factor_required' => true,
        ])->save();
        app(CreatePersonalWorkspaceForUser::class)->handle($admin);

        $settings = InstallationSettings::query()
            ->where('scope', InstallationSettings::SCOPE_INSTALLATION)
            ->first();
        if (!$settings instanceof InstallationSettings) {
            $settings = new InstallationSettings();
        }

        $settings->forceFill([
            'scope' => InstallationSettings::SCOPE_INSTALLATION,
            'max_markdown_bytes' => 32,
            'max_html_bytes' => 64,
            'artifact_max_bytes' => 80,
            'max_workspace_storage_bytes' => 1024,
            'max_page_storage_bytes' => 512,
            'max_page_versions' => 2,
            'max_tags_per_page' => 8,
            'two_factor_required_for_system_admins' => true,
            'two_factor_required_for_all_users' => true,
        ])->save();

        TrustedDevice::query()->forceCreate([
            'user_uid' => $admin->uid,
            'token_hash' => hash('sha256', 'raw-token-' . $email),
            'label' => 'Test browser',
            'user_agent_summary' => 'Test browser',
            'expires_at' => now()->addDays(30),
            'last_used_at' => null,
        ]);

        return $admin;
    }

    /**
     * @param list<string> $values
     */
    private function assertSensitiveValuesAbsentFromSecurityRecords(array $values): void
    {
        $needles = array_values(array_filter(
            $values,
            static fn (string $value): bool => $value !== '',
        ));

        foreach (DomainEvent::query()->get() as $event) {
            $encodedPayload = json_encode($event->payload, JSON_THROW_ON_ERROR);
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $encodedPayload);
            }
        }

        foreach (AuditEntry::query()->get() as $audit) {
            $encodedMetadata = json_encode($audit->metadata, JSON_THROW_ON_ERROR);
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $encodedMetadata);
            }
        }
    }
}
