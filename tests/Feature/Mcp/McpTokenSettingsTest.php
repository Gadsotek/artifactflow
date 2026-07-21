<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Application\Identity\CreateSharedWorkspace;
use App\Application\Identity\ResetUserPassword;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpAccessTokenRevoker;
use App\Domain\DomainRuleViolation;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class McpTokenSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_human_token_mint_requires_enabled_two_factor_and_strong_password_totp_step_up(): void
    {
        $issuer = app(McpAccessTokenIssuer::class);
        $withoutTwoFactor = $this->createUser('No 2FA', 'mcp-no-2fa@example.test');

        try {
            $issuer->issue(
                principal: $withoutTwoFactor,
                name: 'No 2FA token',
                scopes: [McpAccessTokenIssuer::SCOPE_SEARCH],
                expiresAt: now()->addHour(),
            );
            $this->fail('Expected human MCP token minting to require enabled two-factor authentication.');
        } catch (DomainRuleViolation $exception) {
            $this->assertStringContainsString('two-factor', $exception->getMessage());
        }

        $user = $this->enableTwoFactor($this->createUser('MCP Token User', 'mcp-token-user@example.test'));
        $workspace = app(CreateSharedWorkspace::class)->handle($user, 'Scoped Token Team');

        $this->actingAs($user)
            ->post(route('settings.mcp-tokens.store'), [
                'name' => 'Missing code',
                'scopes' => [McpAccessTokenIssuer::SCOPE_SEARCH],
                'expires_in_days' => 30,
                'password' => 'correct horse battery staple',
            ])
            ->assertSessionHasErrors('code');
        $this->assertSame(0, McpAccessToken::query()->count());

        $this->actingAs($user)
            ->post(route('settings.mcp-tokens.store'), [
                'name' => 'Wrong code',
                'scopes' => [McpAccessTokenIssuer::SCOPE_SEARCH],
                'expires_in_days' => 30,
                'password' => 'correct horse battery staple',
                'code' => '000000',
            ])
            ->assertSessionHasErrors('code');
        $this->assertSame(0, McpAccessToken::query()->count());

        $this->actingAs($user)
            ->post(route('settings.mcp-tokens.store'), [
                'name' => 'Wrong password',
                'scopes' => [McpAccessTokenIssuer::SCOPE_SEARCH],
                'expires_in_days' => 30,
                'password' => 'incorrect horse battery staple',
                'code' => $this->currentTotp(),
            ])
            ->assertSessionHasErrors('password');
        $this->assertSame(0, McpAccessToken::query()->count());

        $code = $this->currentTotp();
        $response = $this->actingAs($user)
            ->post(route('settings.mcp-tokens.store'), [
                'name' => 'Workstation agent',
                'scopes' => [McpAccessTokenIssuer::SCOPE_SEARCH, McpAccessTokenIssuer::SCOPE_READ],
                'workspace_uids' => [$workspace->uid],
                'expires_in_days' => 30,
                'password' => 'correct horse battery staple',
                'code' => $code,
            ]);

        $response->assertOk();
        $response->assertSee('af_mcp_');
        $this->assertSame(1, McpAccessToken::query()->count());
        $this->assertSame([$workspace->uid], McpAccessToken::query()->sole()->workspaceUids());

        $this->actingAs($user)
            ->post(route('settings.mcp-tokens.store'), [
                'name' => 'Replayed code',
                'scopes' => [McpAccessTokenIssuer::SCOPE_SEARCH],
                'expires_in_days' => 30,
                'password' => 'correct horse battery staple',
                'code' => $code,
            ])
            ->assertSessionHasErrors('code');
        $this->assertSame(1, McpAccessToken::query()->count());
    }

    public function test_malformed_token_name_is_rejected_before_totp_is_consumed(): void
    {
        $user = $this->enableTwoFactor($this->createUser('Malformed Token User', 'malformed-token@example.test'));
        $workspace = app(CreateSharedWorkspace::class)->handle($user, 'Malformed Token Team');

        $this->actingAs($user)
            ->post(route('settings.mcp-tokens.store'), [
                'name' => "invalid\0token",
                'scopes' => [McpAccessTokenIssuer::SCOPE_SEARCH],
                'workspace_uids' => [$workspace->uid],
                'expires_in_days' => 30,
                'password' => 'correct horse battery staple',
                'code' => $this->currentTotp(),
            ])
            ->assertSessionHasErrors('name');

        $this->assertNull($user->refresh()->two_factor_last_used_timestep);
        $this->assertSame(0, McpAccessToken::query()->count());
    }

    public function test_password_reset_between_step_up_and_issuance_rejects_the_stale_request(): void
    {
        $user = $this->enableTwoFactor($this->createUser('Stale Step Up User', 'stale-step-up@example.test'));
        $workspace = app(CreateSharedWorkspace::class)->handle($user, 'Stale Step Up Team');
        $resetTriggered = false;

        DB::listen(function (QueryExecuted $query) use (&$resetTriggered, $user): void {
            if (
                $resetTriggered
                || !str_starts_with(strtolower($query->sql), 'update "users"')
                || !str_contains(strtolower($query->sql), '"two_factor_last_used_timestep"')
            ) {
                return;
            }

            $resetTriggered = true;
            app(ResetUserPassword::class)->handle($user, 'password changed during issuance');
        });

        $this->actingAs($user)
            ->post(route('settings.mcp-tokens.store'), [
                'name' => 'Stale step-up token',
                'scopes' => [McpAccessTokenIssuer::SCOPE_SEARCH],
                'workspace_uids' => [$workspace->uid],
                'expires_in_days' => 30,
                'password' => 'correct horse battery staple',
                'code' => $this->currentTotp(),
            ])
            ->assertSessionHasErrors([
                'password' => 'Your authentication changed while the token was being created. Confirm your password and authentication code again.',
            ]);

        $this->assertTrue($resetTriggered);
        $this->assertTrue(Hash::check('password changed during issuance', $user->refresh()->password));
        $this->assertSame(0, McpAccessToken::query()->count());
    }

    public function test_token_mint_requires_an_explicit_workspace_selection(): void
    {
        // Least privilege: an empty workspace selection must be rejected, never
        // silently minted as an all-workspaces token.
        $user = $this->enableTwoFactor($this->createUser('Unscoped Minter', 'unscoped-minter@example.test'));

        $this->actingAs($user)
            ->post(route('settings.mcp-tokens.store'), [
                'name' => 'Unscoped token',
                'scopes' => [McpAccessTokenIssuer::SCOPE_SEARCH],
                'expires_in_days' => 30,
                'password' => 'correct horse battery staple',
                'code' => $this->currentTotp(),
            ])
            ->assertSessionHasErrors('workspace_uids');

        $this->assertSame(0, McpAccessToken::query()->count());
    }

    public function test_token_mint_allows_explicit_all_workspaces_scope(): void
    {
        // The "all workspaces" grant must be a deliberate, explicit choice (a
        // checkbox), not the accidental result of an empty selection. When chosen
        // it mints the unrestricted scope that also covers future workspaces.
        $user = $this->enableTwoFactor($this->createUser('All Workspaces Minter', 'all-workspaces-minter@example.test'));
        app(CreateSharedWorkspace::class)->handle($user, 'Present Team');

        $response = $this->actingAs($user)
            ->post(route('settings.mcp-tokens.store'), [
                'name' => 'Org-wide token',
                'scopes' => [McpAccessTokenIssuer::SCOPE_SEARCH],
                'all_workspaces' => '1',
                'expires_in_days' => 30,
                'password' => 'correct horse battery staple',
                'code' => $this->currentTotp(),
            ]);

        $response->assertOk();
        $response->assertSee('af_mcp_');
        $this->assertSame(1, McpAccessToken::query()->count());
        $this->assertNull(McpAccessToken::query()->sole()->workspaceUids());
    }

    public function test_all_workspaces_checkbox_overrides_individual_selection(): void
    {
        // Checking "all workspaces" is authoritative: it grants the unrestricted
        // scope even if stale per-workspace boxes are also submitted.
        $user = $this->enableTwoFactor($this->createUser('Override Minter', 'override-minter@example.test'));
        $workspace = app(CreateSharedWorkspace::class)->handle($user, 'One Team');

        $response = $this->actingAs($user)
            ->post(route('settings.mcp-tokens.store'), [
                'name' => 'Override token',
                'scopes' => [McpAccessTokenIssuer::SCOPE_SEARCH],
                'all_workspaces' => '1',
                'workspace_uids' => [$workspace->uid],
                'expires_in_days' => 30,
                'password' => 'correct horse battery staple',
                'code' => $this->currentTotp(),
            ]);

        $response->assertOk();
        $this->assertNull(McpAccessToken::query()->sole()->workspaceUids());
    }

    public function test_all_workspaces_tokens_may_carry_write_scopes(): void
    {
        // A cross-workspace agent may hold write scopes at the all-workspaces breadth:
        // execution still runs every create/update through the same per-workspace
        // policies as a human (the token is capped at Editor authority), so it can only
        // write where the account already may. The shorter write-token TTL still applies.
        $user = $this->enableTwoFactor($this->createUser('Broad Writer', 'broad-writer@example.test'));
        app(CreateSharedWorkspace::class)->handle($user, 'Broad Team');

        $response = $this->actingAs($user)
            ->post(route('settings.mcp-tokens.store'), [
                'name' => 'Org-wide writer',
                'scopes' => [McpAccessTokenIssuer::SCOPE_SEARCH, McpAccessTokenIssuer::SCOPE_CREATE, McpAccessTokenIssuer::SCOPE_UPDATE],
                'all_workspaces' => '1',
                'expires_in_days' => 30,
                'password' => 'correct horse battery staple',
                'code' => $this->currentTotp(),
            ]);

        $response->assertOk();
        $response->assertSee('af_mcp_');

        $token = McpAccessToken::query()->sole();
        $this->assertNull($token->workspaceUids());
        $this->assertContains(McpAccessTokenIssuer::SCOPE_CREATE, $token->scopes);
        $this->assertContains(McpAccessTokenIssuer::SCOPE_UPDATE, $token->scopes);
    }

    public function test_all_workspaces_write_tokens_still_obey_the_shorter_write_ttl(): void
    {
        // Dropping the read-only restriction must not loosen the write-token TTL cap:
        // an org-wide write credential is still held to the shorter lifetime.
        $user = $this->enableTwoFactor($this->createUser('Broad Long Writer', 'broad-long-writer@example.test'));
        app(CreateSharedWorkspace::class)->handle($user, 'Broad Long Team');

        $this->actingAs($user)
            ->post(route('settings.mcp-tokens.store'), [
                'name' => 'Org-wide long writer',
                'scopes' => [McpAccessTokenIssuer::SCOPE_SEARCH, McpAccessTokenIssuer::SCOPE_CREATE],
                'all_workspaces' => '1',
                'expires_in_days' => 365,
                'password' => 'correct horse battery staple',
                'code' => $this->currentTotp(),
            ])
            ->assertSessionHasErrors('expires_in_days');

        $this->assertSame(0, McpAccessToken::query()->count());
    }

    public function test_write_scoped_tokens_cannot_exceed_the_shorter_ttl(): void
    {
        // A year-long standing write credential for an autonomous agent is too long
        // an exposure window; write-capable tokens are capped to a shorter lifetime.
        $user = $this->enableTwoFactor($this->createUser('Long Writer', 'long-writer@example.test'));
        $workspace = app(CreateSharedWorkspace::class)->handle($user, 'Writer Team');

        $this->actingAs($user)
            ->post(route('settings.mcp-tokens.store'), [
                'name' => 'Long-lived writer',
                'scopes' => [McpAccessTokenIssuer::SCOPE_CREATE, McpAccessTokenIssuer::SCOPE_UPDATE],
                'workspace_uids' => [$workspace->uid],
                'expires_in_days' => 365,
                'password' => 'correct horse battery staple',
                'code' => $this->currentTotp(),
            ])
            ->assertSessionHasErrors('expires_in_days');

        $this->assertSame(0, McpAccessToken::query()->count());
    }

    public function test_token_mint_rejects_workspaces_the_principal_cannot_access(): void
    {
        $user = $this->enableTwoFactor($this->createUser('Scoped Minter', 'scoped-minter@example.test'));
        $outsider = $this->createUser('Outside Owner', 'outside-owner@example.test');
        $foreignWorkspace = app(CreateSharedWorkspace::class)->handle($outsider, 'Foreign Team');

        $this->actingAs($user)
            ->post(route('settings.mcp-tokens.store'), [
                'name' => 'Overreaching token',
                'scopes' => [McpAccessTokenIssuer::SCOPE_SEARCH],
                'workspace_uids' => [$foreignWorkspace->uid],
                'expires_in_days' => 30,
                'password' => 'correct horse battery staple',
                'code' => $this->currentTotp(),
            ])
            ->assertSessionHasErrors('workspace_uids');
        $this->assertSame(0, McpAccessToken::query()->count());
    }

    public function test_mcp_token_settings_scope_to_own_tokens_and_revoke_without_strong_step_up(): void
    {
        $user = $this->enableTwoFactor($this->createUser('Token Owner', 'token-owner@example.test'));
        $otherUser = $this->enableTwoFactor($this->createUser('Other Token Owner', 'other-token-owner@example.test'));
        $ownToken = app(McpAccessTokenIssuer::class)->issue(
            principal: $user,
            name: 'Visible token',
            scopes: [McpAccessTokenIssuer::SCOPE_SEARCH],
            expiresAt: now()->addHour(),
        );
        $otherToken = app(McpAccessTokenIssuer::class)->issue(
            principal: $otherUser,
            name: 'Hidden token',
            scopes: [McpAccessTokenIssuer::SCOPE_SEARCH],
            expiresAt: now()->addHour(),
        );

        $this->actingAs($user)
            ->get(route('settings.mcp-tokens.index'))
            ->assertOk()
            ->assertSee('Visible token')
            ->assertDontSee('Hidden token')
            ->assertDontSee($ownToken->plainTextToken);

        $this->actingAs($user)
            ->delete(route('settings.mcp-tokens.destroy', $otherToken->accessToken))
            ->assertNotFound();
        $this->assertNull($otherToken->accessToken->refresh()->revoked_at);

        $this->actingAs($user)
            ->delete(route('settings.mcp-tokens.destroy', $ownToken->accessToken))
            ->assertRedirect(route('settings.mcp-tokens.index'));
        $this->assertNotNull($ownToken->accessToken->refresh()->revoked_at);
    }

    public function test_revoking_two_stale_instances_of_the_same_token_records_history_once(): void
    {
        $user = $this->enableTwoFactor($this->createUser('Token Owner', 'idempotent-revoke@example.test'));
        $issued = app(McpAccessTokenIssuer::class)->issue(
            principal: $user,
            name: 'Concurrent revoke token',
            scopes: [McpAccessTokenIssuer::SCOPE_SEARCH],
            expiresAt: now()->addHour(),
        );
        $firstRequestToken = McpAccessToken::query()->findOrFail($issued->accessToken->uid);
        $secondRequestToken = McpAccessToken::query()->findOrFail($issued->accessToken->uid);
        $revoker = app(McpAccessTokenRevoker::class);

        $this->assertTrue($revoker->revoke($firstRequestToken, $user, 'self-service'));
        $this->assertFalse($revoker->revoke($secondRequestToken, $user, 'self-service'));

        $this->assertSame(1, DomainEvent::query()
            ->where('event_type', 'mcp_token.revoked')
            ->where('aggregate_uid', $issued->accessToken->uid)
            ->count());
        $this->assertSame(1, AuditEntry::query()
            ->where('action', 'mcp_token.revoked')
            ->where('auditable_uid', $issued->accessToken->uid)
            ->count());
    }

    public function test_settings_page_explains_how_to_connect_and_use_mcp_clients(): void
    {
        $user = $this->enableTwoFactor($this->createUser('Guide User', 'mcp-guide-user@example.test'));
        $issued = app(McpAccessTokenIssuer::class)->issue(
            principal: $user,
            name: 'Guide token',
            scopes: [McpAccessTokenIssuer::SCOPE_SEARCH],
            expiresAt: now()->addHour(),
        );

        $this->actingAs($user)
            ->get(route('settings.mcp-tokens.index'))
            ->assertOk()
            ->assertSee('Connect your AI client')
            ->assertSee(route('mcp'))
            ->assertSee('Authorization')
            ->assertSee('Bearer YOUR_TOKEN')
            ->assertSee('Laravel MCP')
            ->assertSee('MCP-Session-Id')
            ->assertSee('search first')
            ->assertSee('read a specific page')
            ->assertSee('base_version_uid')
            ->assertSee('untrusted data')
            ->assertSee(McpAccessTokenIssuer::SCOPE_SEARCH)
            ->assertSee(McpAccessTokenIssuer::SCOPE_READ)
            ->assertSee(McpAccessTokenIssuer::SCOPE_CREATE)
            ->assertSee(McpAccessTokenIssuer::SCOPE_UPDATE)
            ->assertDontSee($issued->plainTextToken);
    }

    public function test_token_mint_and_revoke_are_audited_without_token_material(): void
    {
        $user = $this->enableTwoFactor($this->createUser('Audited Token User', 'audited-token-user@example.test'));
        $workspace = app(CreateSharedWorkspace::class)->handle($user, 'Audited Team');
        $code = $this->currentTotp();

        $response = $this->actingAs($user)
            ->post(route('settings.mcp-tokens.store'), [
                'name' => 'Audited token',
                'scopes' => [McpAccessTokenIssuer::SCOPE_SEARCH],
                'workspace_uids' => [$workspace->uid],
                'expires_in_days' => 30,
                'password' => 'correct horse battery staple',
                'code' => $code,
            ]);

        $response->assertOk();
        $plainTextToken = $this->extractPlainTextToken((string) $response->getContent());
        $token = McpAccessToken::query()->sole();

        $createdAudit = AuditEntry::query()
            ->where('action', 'mcp_token.created')
            ->sole();
        $createdEvent = DomainEvent::query()
            ->where('event_type', 'mcp_token.created')
            ->sole();

        $createdMaterial = json_encode([$createdAudit->metadata, $createdEvent->payload], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($plainTextToken, $createdMaterial);
        $this->assertStringNotContainsString($token->token_hash, $createdMaterial);
        $this->assertStringNotContainsString('token_hash', $createdMaterial);

        $this->actingAs($user)
            ->delete(route('settings.mcp-tokens.destroy', $token))
            ->assertRedirect(route('settings.mcp-tokens.index'));

        $revokedAudit = AuditEntry::query()
            ->where('action', 'mcp_token.revoked')
            ->sole();
        $revokedEvent = DomainEvent::query()
            ->where('event_type', 'mcp_token.revoked')
            ->sole();
        $revokedMaterial = json_encode([$revokedAudit->metadata, $revokedEvent->payload], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($plainTextToken, $revokedMaterial);
        $this->assertStringNotContainsString($token->token_hash, $revokedMaterial);
        $this->assertStringNotContainsString('token_hash', $revokedMaterial);
    }

    public function test_console_service_account_token_list_and_revoke_do_not_print_token_values(): void
    {
        $owner = $this->createUser('CLI Owner', 'cli-owner@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($owner, 'CLI Team');
        $human = $this->enableTwoFactor($this->createUser('CLI Human', 'cli-human@example.test'));

        $humanExitCode = Artisan::call('artifactflow:mcp-token-create', [
            '--email' => $human->email,
            '--workspace' => [$workspace->uid],
            '--scope' => [McpAccessTokenIssuer::SCOPE_SEARCH],
        ]);

        $this->assertSame(1, $humanExitCode);
        $this->assertStringContainsString('service accounts', Artisan::output());

        $createExitCode = Artisan::call('artifactflow:mcp-token-create', [
            '--email' => 'cli-agent@example.test',
            '--workspace' => [$workspace->uid],
            '--scope' => [McpAccessTokenIssuer::SCOPE_SEARCH],
        ]);

        $this->assertSame(0, $createExitCode);
        $createOutput = Artisan::output();
        preg_match('/MCP token: (?<token>af_mcp_[A-Za-z0-9]+)/', $createOutput, $matches);
        $plainTextToken = $matches['token'] ?? null;
        $this->assertIsString($plainTextToken);
        $token = McpAccessToken::query()->sole();

        $listExitCode = Artisan::call('artifactflow:mcp-token-list', [
            '--email' => 'cli-agent@example.test',
        ]);

        $this->assertSame(0, $listExitCode);
        $listOutput = Artisan::output();
        $this->assertStringContainsString($token->uid, $listOutput);
        $this->assertStringNotContainsString($plainTextToken, $listOutput);

        $revokeExitCode = Artisan::call('artifactflow:mcp-token-revoke', [
            '--uid' => $token->uid,
        ]);

        $this->assertSame(0, $revokeExitCode);
        $revokeOutput = Artisan::output();
        $this->assertStringContainsString($token->uid, $revokeOutput);
        $this->assertStringNotContainsString($plainTextToken, $revokeOutput);
        $this->assertNotNull($token->refresh()->revoked_at);
    }

    private function createUser(string $name, string $email): User
    {
        return User::query()->forceCreate([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => Hash::make('correct horse battery staple'),
        ]);
    }

    private function enableTwoFactor(User $user): User
    {
        $user->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [Hash::make('ABCD2-EFGH3')],
            'two_factor_last_used_timestep' => null,
        ])->save();

        return $user->refresh();
    }

    private function currentTotp(): string
    {
        return app(Google2FA::class)->getCurrentOtp('JBSWY3DPEHPK3PXP');
    }

    private function extractPlainTextToken(string $html): string
    {
        preg_match('/af_mcp_[A-Za-z0-9]+/', $html, $matches);
        $plainTextToken = $matches[0] ?? null;
        $this->assertIsString($plainTextToken);

        return $plainTextToken;
    }
}
