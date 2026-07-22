<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Application\Identity\CreatePersonalWorkspaceForUser;
use App\Application\Identity\ResetUserPassword;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Http\Support\PasswordResetTokenReviewNotice;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class PasswordResetTokenReviewNoticeTest extends TestCase
{
    use RefreshDatabase;

    private const string OLD_PASSWORD = 'correct horse battery staple';
    private const string NEW_PASSWORD = 'new correct horse battery staple';
    private const string TWO_FACTOR_SECRET = 'JBSWY3DPEHPK3PXP';

    public function test_first_login_after_reset_shows_active_token_review_notice_once_without_credential_details(): void
    {
        $user = $this->createUser('Reset Notice User', 'reset-notice@example.test');
        $firstRawToken = 'af_mcp_first-secret-value';
        $secondRawToken = 'af_mcp_second-secret-value';
        $firstToken = $this->createToken($user, 'Private workstation token', $firstRawToken);
        $secondToken = $this->createToken($user, 'Private automation token', $secondRawToken);

        app(ResetUserPassword::class)->handle($user, self::NEW_PASSWORD);

        $this->assertNotNull($user->refresh()->password_reset_notice_pending_at);

        $this->post('/login', [
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ])
            ->assertRedirect('/dashboard')
            ->assertSessionHas(PasswordResetTokenReviewNotice::SESSION_KEY, 2);

        $this->assertNull($user->refresh()->password_reset_notice_pending_at);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('data-password-reset-token-review-notice', escape: false)
            ->assertSee('data-auto-open-editor-dialog', escape: false)
            ->assertSee('Your password was recently reset.')
            ->assertSee('2 active MCP tokens were not revoked')
            ->assertSee(route('settings.mcp-tokens.index'))
            ->assertDontSee($firstRawToken)
            ->assertDontSee($secondRawToken)
            ->assertDontSee($firstToken->name)
            ->assertDontSee($secondToken->name)
            ->assertDontSee($firstToken->token_hash)
            ->assertDontSee($secondToken->token_hash);

        $this->post('/logout')->assertRedirect('/');

        $this->post('/login', [
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ])
            ->assertRedirect('/dashboard')
            ->assertSessionMissing(PasswordResetTokenReviewNotice::SESSION_KEY);

        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee('data-password-reset-token-review-notice', escape: false);
    }

    public function test_reset_with_no_tokens_clears_pending_notice_without_showing_dialog(): void
    {
        $user = $this->createUser('Tokenless Reset User', 'tokenless-reset@example.test');
        app(ResetUserPassword::class)->handle($user, self::NEW_PASSWORD);

        $this->post('/login', [
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ])
            ->assertRedirect('/dashboard')
            ->assertSessionMissing(PasswordResetTokenReviewNotice::SESSION_KEY);

        $this->assertNull($user->refresh()->password_reset_notice_pending_at);
        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee('data-password-reset-token-review-notice', escape: false);
    }

    public function test_expired_and_revoked_tokens_do_not_trigger_notice(): void
    {
        $user = $this->createUser('Inactive Token User', 'inactive-token-reset@example.test');
        $this->createToken(
            $user,
            'Expired token',
            'af_mcp_expired-secret-value',
            expiresAt: now()->subMinute(),
        );
        $this->createToken(
            $user,
            'Revoked token',
            'af_mcp_revoked-secret-value',
            revokedAt: now()->subMinute(),
        );
        app(ResetUserPassword::class)->handle($user, self::NEW_PASSWORD);

        $this->post('/login', [
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ])
            ->assertRedirect('/dashboard')
            ->assertSessionMissing(PasswordResetTokenReviewNotice::SESSION_KEY);

        $this->assertNull($user->refresh()->password_reset_notice_pending_at);
    }

    public function test_login_without_prior_reset_does_not_show_notice_for_active_tokens(): void
    {
        $user = $this->createUser('Ordinary Login User', 'ordinary-login@example.test');
        $this->createToken($user, 'Ordinary active token', 'af_mcp_ordinary-secret-value');

        $this->post('/login', [
            'email' => $user->email,
            'password' => self::OLD_PASSWORD,
        ])
            ->assertRedirect('/dashboard')
            ->assertSessionMissing(PasswordResetTokenReviewNotice::SESSION_KEY);

        $this->get('/dashboard')
            ->assertOk()
            ->assertDontSee('data-password-reset-token-review-notice', escape: false);
    }

    public function test_two_factor_completion_consumes_notice_after_binding_authentication_revision(): void
    {
        $user = $this->createUser('Two Factor Reset User', 'two-factor-reset-notice@example.test');
        $this->enableTwoFactor($user);
        $this->createToken($user, 'Two factor active token', 'af_mcp_two-factor-secret-value');
        app(ResetUserPassword::class)->handle($user, self::NEW_PASSWORD);

        $this->post('/login', [
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ])->assertRedirect('/login/two-factor-challenge');

        $this->assertNotNull($user->refresh()->password_reset_notice_pending_at);

        $this->post('/login/two-factor-challenge', [
            'code' => (new Google2FA())->getCurrentOtp(self::TWO_FACTOR_SECRET),
        ])
            ->assertRedirect('/dashboard')
            ->assertSessionHas(PasswordResetTokenReviewNotice::SESSION_KEY, 1);

        $this->assertNull($user->refresh()->password_reset_notice_pending_at);
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('data-password-reset-token-review-notice', escape: false)
            ->assertSee('1 active MCP token was not revoked');
    }

    public function test_in_flight_old_password_login_cannot_consume_reset_notice(): void
    {
        $user = $this->createUser('Reset Notice Race User', 'reset-notice-race@example.test');
        $this->createToken($user, 'Race active token', 'af_mcp_race-secret-value');
        $resetTriggered = false;

        DB::listen(function (QueryExecuted $query) use (&$resetTriggered, $user): void {
            if (
                $resetTriggered
                || !str_starts_with(strtolower($query->sql), 'select * from "users"')
                || !in_array($user->email, $query->bindings, true)
            ) {
                return;
            }

            $resetTriggered = true;
            app(ResetUserPassword::class)->handle($user, self::NEW_PASSWORD);
        });

        $this->post('/login', [
            'email' => $user->email,
            'password' => self::OLD_PASSWORD,
        ])
            ->assertRedirect('/dashboard')
            ->assertSessionMissing(PasswordResetTokenReviewNotice::SESSION_KEY);

        $this->assertTrue($resetTriggered);
        $this->assertNotNull($user->refresh()->password_reset_notice_pending_at);
        Auth::forgetGuards();
        $this->get('/dashboard')->assertRedirect('/login');

        $this->post('/login', [
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ])
            ->assertRedirect('/dashboard')
            ->assertSessionHas(PasswordResetTokenReviewNotice::SESSION_KEY, 1);

        $this->assertNull($user->refresh()->password_reset_notice_pending_at);
    }

    private function createUser(string $name, string $email): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(self::OLD_PASSWORD),
        ]);
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        app(CreatePersonalWorkspaceForUser::class)->handle($user);

        return $user;
    }

    private function createToken(
        User $user,
        string $name,
        string $rawToken,
        ?Carbon $expiresAt = null,
        ?Carbon $revokedAt = null,
    ): McpAccessToken {
        return McpAccessToken::query()->forceCreate([
            'principal_user_uid' => $user->uid,
            'name' => $name,
            'token_hash' => McpAccessTokenIssuer::hashToken($rawToken),
            'scopes' => [McpAccessTokenIssuer::SCOPE_SEARCH],
            'workspace_uids' => null,
            'expires_at' => $expiresAt ?? now()->addDay(),
            'revoked_at' => $revokedAt,
        ]);
    }

    private function enableTwoFactor(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => self::TWO_FACTOR_SECRET,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [],
            'two_factor_last_used_timestep' => null,
        ])->save();
    }
}
