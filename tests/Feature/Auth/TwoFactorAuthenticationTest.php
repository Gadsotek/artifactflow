<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Application\Identity\CreatePersonalWorkspaceForUser;
use App\Application\Identity\ResetUserPassword;
use App\Application\Identity\TrustedDeviceManager;
use App\Application\Identity\TwoFactorPendingChallenge;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\McpAccessToken;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET = 'JBSWY3DPEHPK3PXP';

    public function test_two_factor_enrollment_password_confirmation_uses_its_own_timeout(): void
    {
        config([
            'auth.password_timeout' => 60,
            'auth.admin_password_timeout' => 900,
            'auth.two_factor_enrollment_password_timeout' => 60,
        ]);

        $user = $this->createUser('Account Step Up User', 'account-step-up@example.test');

        $this->actingAs($user)
            ->withSession([
                RequireRecentPasswordConfirmation::SESSION_KEY => now()->subSeconds(61)->getTimestamp(),
            ])
            ->post('/settings/two-factor/enroll')
            ->assertRedirect('/settings/confirm-password');

        $this->assertNull($user->refresh()->two_factor_secret);
    }

    public function test_two_factor_challenge_displays_the_configured_trusted_device_lifetime(): void
    {
        config(['auth.two_factor_trusted_device_days' => 7]);

        $user = $this->createUser('Trusted Lifetime User', 'trusted-lifetime@example.test');
        $this->enableTwoFactor($user);

        $this->post('/login', [
            'email' => 'trusted-lifetime@example.test',
            'password' => 'correct horse battery staple',
        ])->assertRedirect('/login/two-factor-challenge');

        $this->get('/login/two-factor-challenge')
            ->assertOk()
            ->assertSee('Remember this device for 7 days')
            ->assertDontSee('Remember this device for 30 days');
    }

    public function test_two_factor_challenge_hides_recovery_code_behind_an_explicit_mode_switch(): void
    {
        $user = $this->createUser('Recovery Mode User', 'recovery-mode@example.test');
        $this->enableTwoFactor($user);

        $this->post('/login', [
            'email' => 'recovery-mode@example.test',
            'password' => 'correct horse battery staple',
        ])->assertRedirect('/login/two-factor-challenge');

        $this->get('/login/two-factor-challenge')
            ->assertOk()
            ->assertSee('data-two-factor-challenge', escape: false)
            ->assertSee('data-two-factor-recovery-panel hidden', escape: false)
            ->assertSee('data-two-factor-recovery-input', escape: false)
            ->assertSee('class="af-auth-mode-toggle"', escape: false)
            ->assertSee('Use a recovery code', escape: false)
            ->assertSee('<noscript>', escape: false)
            ->assertSee('aria-expanded="false"', escape: false);
    }

    public function test_two_factor_password_step_never_creates_an_authenticated_half_session(): void
    {
        Event::fake([Login::class]);
        $user = $this->createUser('Two Factor User', 'two-factor@example.test');
        $this->enableTwoFactor($user);
        $initialSessionId = $this->app['session']->getId();

        $this->post('/login', [
            'email' => 'two-factor@example.test',
            'password' => 'correct horse battery staple',
        ])->assertRedirect('/login/two-factor-challenge');

        $this->assertGuest();
        $this->assertNotSame($initialSessionId, $this->app['session']->getId());
        Event::assertNotDispatched(Login::class);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'user.logged_in')->count());

        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_valid_totp_completes_login_once_and_rejects_replay(): void
    {
        $user = $this->createUser('Two Factor User', 'totp@example.test');
        $this->enableTwoFactor($user);
        $code = $this->currentOtp(self::SECRET);

        $this->post('/login', [
            'email' => 'totp@example.test',
            'password' => 'correct horse battery staple',
        ])->assertRedirect('/login/two-factor-challenge');
        $challengeSessionId = $this->app['session']->getId();

        $this->post('/login/two-factor-challenge', [
            'code' => $code,
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($challengeSessionId, $this->app['session']->getId());
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'user.logged_in')->count());
        $this->assertNotNull($user->refresh()->two_factor_last_used_timestep);

        $this->post('/logout')->assertRedirect('/');
        $this->post('/login', [
            'email' => 'totp@example.test',
            'password' => 'correct horse battery staple',
        ])->assertRedirect('/login/two-factor-challenge');

        $this->post('/login/two-factor-challenge', [
            'code' => $code,
        ])->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'user.logged_in')->count());
    }

    public function test_recovery_code_is_hash_only_key_independent_and_single_use(): void
    {
        $user = $this->createUser('Recovery User', 'recovery@example.test');
        $this->enableTwoFactor($user, recoveryCodes: ['ABCD2-EFGH3']);
        DB::table('users')
            ->where('uid', $user->uid)
            ->update(['two_factor_secret' => 'not-a-valid-encrypted-secret']);

        $this->post('/login', [
            'email' => 'recovery@example.test',
            'password' => 'correct horse battery staple',
        ])->assertRedirect('/login/two-factor-challenge');

        $this->post('/login/two-factor-challenge', [
            'recovery_code' => 'ABCD2-EFGH3',
            'remember_device' => '1',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $remainingRecoveryCodes = $user->refresh()->two_factor_recovery_codes;
        $this->assertIsArray($remainingRecoveryCodes);
        $this->assertCount(0, $remainingRecoveryCodes);
        $this->assertSame(0, TrustedDevice::query()->where('user_uid', $user->uid)->count());

        $this->post('/logout')->assertRedirect('/');
        $this->post('/login', [
            'email' => 'recovery@example.test',
            'password' => 'correct horse battery staple',
        ])->assertRedirect('/login/two-factor-challenge');

        $this->post('/login/two-factor-challenge', [
            'recovery_code' => 'ABCD2-EFGH3',
        ])
            ->assertSessionHasErrors('code')
            ->assertSessionMissing('_old_input.recovery_code');

        $this->assertGuest();
    }

    public function test_enrollment_requires_confirmation_and_stores_recovery_codes_as_hashes(): void
    {
        $user = $this->createUser('Enrollment User', 'enroll@example.test');

        $this->actingAs($user);

        $this->post('/settings/two-factor/enroll')
            ->assertRedirect('/settings/confirm-password');
        $this->assertNull($user->refresh()->two_factor_secret);

        $this->post('/settings/confirm-password', [
            'password' => 'correct horse battery staple',
        ])->assertRedirect('/settings/two-factor')
            ->assertSessionHas('status', 'Password confirmed.');

        $this->post('/settings/two-factor/enroll')
            ->assertRedirect('/settings/two-factor');

        $user->refresh();
        $this->assertFalse($user->hasEnabledTwoFactor());
        $this->assertIsString($user->two_factor_secret);
        $this->assertNotNull($user->two_factor_secret_created_at);
        $this->assertNotSame(
            $user->two_factor_secret,
            DB::table('users')->where('uid', $user->uid)->value('two_factor_secret'),
        );

        $this->post('/settings/two-factor/confirm', ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertFalse($user->refresh()->hasEnabledTwoFactor());

        $this->post('/settings/two-factor/confirm', [
            'code' => $this->currentOtp((string) $user->two_factor_secret),
        ])
            ->assertRedirect('/settings/two-factor')
            ->assertSessionHas('status', 'Two-factor authentication enabled.');

        $user->refresh();
        $this->assertTrue($user->hasEnabledTwoFactor());
        $recoveryCodeHashes = $user->two_factor_recovery_codes;
        $this->assertIsArray($recoveryCodeHashes);
        $this->assertCount(10, $recoveryCodeHashes);
        $this->assertDatabaseMissing('users', [
            'uid' => $user->uid,
            'two_factor_recovery_codes' => 'ABCD2-EFGH3',
        ]);

        $plainRecoveryCodes = $this->app['session']->get('two_factor_recovery_codes');
        $this->assertIsArray($plainRecoveryCodes);
        $plainRecoveryCodes = array_values(array_filter(
            $plainRecoveryCodes,
            static fn (mixed $code): bool => is_string($code),
        ));

        $this->assertSensitiveValuesAbsentFromTwoFactorRecords(array_merge(
            [(string) $user->two_factor_secret],
            $plainRecoveryCodes,
        ));
    }

    public function test_expired_enrollment_requires_password_confirmation_and_a_fresh_secret(): void
    {
        $user = $this->createUser('Confirm Step Up User', 'confirm-step-up@example.test');

        $this->actingAs($user)
            ->withSession([RequireRecentPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->post('/settings/two-factor/enroll')
            ->assertRedirect('/settings/two-factor');

        $user->refresh();
        $secret = (string) $user->two_factor_secret;

        $this->travel(181)->seconds();

        $this->post('/settings/two-factor/confirm', [
                'code' => $this->currentOtp($secret),
            ])->assertRedirect('/settings/confirm-password');
        $this->assertFalse($user->refresh()->hasEnabledTwoFactor());

        $this->post('/settings/confirm-password', [
            'password' => 'correct horse battery staple',
        ])->assertRedirect('/settings/two-factor')
            ->assertSessionHas('status', 'Password confirmed.');

        $this->get('/settings/two-factor')
            ->assertOk()
            ->assertDontSee($secret)
            ->assertSee('Start enrollment');

        $this->post('/settings/two-factor/confirm', [
            'code' => $this->currentOtp($secret),
        ])->assertSessionHasErrors('code');

        $this->post('/settings/two-factor/enroll')
            ->assertRedirect('/settings/two-factor');

        $refreshedSecret = (string) $user->refresh()->two_factor_secret;
        $this->assertNotSame($secret, $refreshedSecret);

        $this->post('/settings/two-factor/confirm', [
            'code' => $this->currentOtp($refreshedSecret),
        ])->assertRedirect('/settings/two-factor')
            ->assertSessionHas('status', 'Two-factor authentication enabled.');

        $this->assertTrue($user->refresh()->hasEnabledTwoFactor());
    }

    public function test_settings_page_renders_pending_qr_and_shows_recovery_codes_once(): void
    {
        $user = $this->createUser('Settings User', 'settings-2fa@example.test');

        $this->actingAs($user);
        $this->post('/settings/confirm-password', [
            'password' => 'correct horse battery staple',
        ])->assertRedirect('/settings/two-factor');

        $this->post('/settings/two-factor/enroll')
            ->assertRedirect('/settings/two-factor');

        $user->refresh();
        $this->get('/settings/two-factor')
            ->assertOk()
            ->assertSee('data:image/svg+xml;base64')
            ->assertSee((string) $user->two_factor_secret);

        $this->followingRedirects()
            ->post('/settings/two-factor/confirm', [
                'code' => $this->currentOtp((string) $user->two_factor_secret),
            ])
            ->assertOk()
            ->assertSee('Recovery codes');

        $this->assertTrue($user->refresh()->hasEnabledTwoFactor());
        $this->actingAs($user)
            ->get('/settings/two-factor')
            ->assertOk()
            ->assertSee('Two-factor authentication is enabled');
    }

    public function test_two_factor_management_requires_step_up_and_can_regenerate_and_disable(): void
    {
        $user = $this->createUser('Management User', 'management-2fa@example.test');
        $this->enableTwoFactor($user);
        $this->createTrustedDevice($user, 'management-device');
        $mcpToken = app(McpAccessTokenIssuer::class)->issue(
            principal: $user,
            name: '2FA disable token',
            scopes: [McpAccessTokenIssuer::SCOPE_SEARCH],
            expiresAt: now()->addHour(),
        )->accessToken;

        $this->actingAs($user)
            ->post('/settings/two-factor/recovery-codes')
            ->assertRedirect('/settings/confirm-password');

        $sessionId = $this->app['session']->getId();
        $this->actingAs($user)
            ->get('/settings/confirm-password')
            ->assertOk()
            ->assertSee('Confirm password');
        $this->actingAs($user)
            ->post('/settings/confirm-password', ['password' => 'wrong password'])
            ->assertSessionHasErrors('password');
        $this->actingAs($user)
            ->post('/settings/confirm-password', ['password' => 'correct horse battery staple'])
            ->assertRedirect('/settings/two-factor')
            ->assertSessionHas('status', 'Password confirmed.');
        $this->assertNotSame($sessionId, $this->app['session']->getId());

        // Regenerating recovery codes now also requires a live second factor;
        // authorize it with one of the enrolled recovery codes.
        $this->followingRedirects()
            ->post('/settings/two-factor/recovery-codes', ['recovery_code' => 'ABCD2-EFGH3'])
            ->assertOk()
            ->assertSee('Recovery codes');
        $this->assertSame(0, TrustedDevice::query()->where('user_uid', $user->uid)->count());
        $recoveryCodeHashes = $user->refresh()->two_factor_recovery_codes;
        $this->assertIsArray($recoveryCodeHashes);
        $this->assertCount(10, $recoveryCodeHashes);

        // Disabling requires a live second factor too; a current authenticator
        // code proves possession (the recovery code above did not advance the
        // TOTP step, so this code is still unused).
        $this->post('/settings/two-factor/disable', ['code' => $this->currentOtp(self::SECRET)])
            ->assertRedirect('/settings/two-factor')
            ->assertSessionHas('status', 'Two-factor authentication disabled.');

        $user->refresh();
        $this->assertFalse($user->hasEnabledTwoFactor());
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNotNull($mcpToken->refresh()->revoked_at);
        $this->assertSame(1, McpAccessToken::query()->where('principal_user_uid', $user->uid)->count());

        $audit = AuditEntry::query()->where('action', 'user.two_factor.disabled')->sole();
        $this->assertSame(1, $audit->metadata['mcp_tokens_revoked']);
    }

    public function test_disabling_two_factor_requires_a_valid_second_factor_beyond_password_confirmation(): void
    {
        $user = $this->createUser('Second Factor User', 'second-factor-required@example.test');
        $this->enableTwoFactor($user);

        // A recent password confirmation is present but is deliberately not
        // enough on its own: the request carries no live second factor.
        $confirmedSession = [RequireRecentPasswordConfirmation::SESSION_KEY => now()->getTimestamp()];

        $this->actingAs($user)
            ->withSession($confirmedSession)
            ->post('/settings/two-factor/disable')
            ->assertSessionHasErrors('code');
        $this->assertTrue($user->refresh()->hasEnabledTwoFactor());

        $this->actingAs($user)
            ->withSession($confirmedSession)
            ->post('/settings/two-factor/disable', ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertTrue($user->refresh()->hasEnabledTwoFactor());

        // Regenerating recovery codes is guarded the same way.
        $this->actingAs($user)
            ->withSession($confirmedSession)
            ->post('/settings/two-factor/recovery-codes')
            ->assertSessionHasErrors('code');

        // A valid authenticator code finally authorizes the disable.
        $this->actingAs($user)
            ->withSession($confirmedSession)
            ->post('/settings/two-factor/disable', ['code' => $this->currentOtp(self::SECRET)])
            ->assertRedirect('/settings/two-factor')
            ->assertSessionHas('status', 'Two-factor authentication disabled.');
        $this->assertFalse($user->refresh()->hasEnabledTwoFactor());
    }

    public function test_trusted_devices_can_be_revoked_after_step_up(): void
    {
        $user = $this->createUser('Device Management User', 'device-management@example.test');
        $this->enableTwoFactor($user);
        $firstDevice = $this->createTrustedDevice($user, 'first-device');
        $this->createTrustedDevice($user, 'second-device');

        $this->actingAs($user)
            ->withSession([RequireRecentPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->delete('/settings/two-factor/trusted-devices/' . $firstDevice->uid)
            ->assertRedirect('/settings/two-factor')
            ->assertSessionHas('status', 'Trusted device revoked.');

        $this->assertSame(1, TrustedDevice::query()->where('user_uid', $user->uid)->count());

        $this->actingAs($user)
            ->withSession([RequireRecentPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->delete('/settings/two-factor/trusted-devices')
            ->assertRedirect('/settings/two-factor')
            ->assertSessionHas('status', 'Trusted devices revoked.');

        $this->assertSame(0, TrustedDevice::query()->where('user_uid', $user->uid)->count());
        $this->assertSame(
            1,
            DomainEvent::query()->where('event_type', 'user.two_factor.trusted_device_revoked')->count(),
        );
        $this->assertSame(
            1,
            DomainEvent::query()->where('event_type', 'user.two_factor.trusted_devices_revoked_all')->count(),
        );
    }

    public function test_revoking_another_users_trusted_device_is_indistinguishable_from_a_missing_one(): void
    {
        $user = $this->createUser('Device Owner User', 'device-owner@example.test');
        $this->enableTwoFactor($user);
        $victimDevice = $this->createTrustedDevice($user, 'victim-device');

        $attacker = $this->createUser('Device Prober User', 'device-prober@example.test');
        $this->enableTwoFactor($attacker);

        $this->actingAs($attacker)
            ->withSession([RequireRecentPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->delete('/settings/two-factor/trusted-devices/' . $victimDevice->uid)
            ->assertNotFound();

        $this->assertSame(1, TrustedDevice::query()->where('user_uid', $user->uid)->count());
    }

    public function test_trusted_device_skips_only_the_totp_challenge_after_password_and_is_revocable(): void
    {
        $user = $this->createUser('Trusted Device User', 'trusted@example.test');
        $this->enableTwoFactor($user);
        $otherUser = $this->createUser('Other Trusted User', 'other-trusted@example.test');
        $this->enableTwoFactor($otherUser);

        $this->post('/login', [
            'email' => 'trusted@example.test',
            'password' => 'correct horse battery staple',
        ])->assertRedirect('/login/two-factor-challenge');
        $response = $this->post('/login/two-factor-challenge', [
            'code' => $this->currentOtp(self::SECRET),
            'remember_device' => '1',
        ])->assertRedirect('/dashboard');
        $cookie = null;
        foreach ($response->headers->getCookies() as $responseCookie) {
            if ($responseCookie->getName() === TrustedDeviceManager::COOKIE_NAME) {
                $cookie = $responseCookie;
            }
        }

        $this->assertNotNull($cookie);
        $cookieValue = $cookie->getValue();
        $this->assertIsString($cookieValue);
        $this->assertSame(1, TrustedDevice::query()->where('user_uid', $user->uid)->count());
        $trustedDevice = TrustedDevice::query()->where('user_uid', $user->uid)->sole();
        $originalExpiry = $trustedDevice->expires_at;
        $originalLastUsed = $trustedDevice->last_used_at;
        $this->assertNotSame($cookieValue, $trustedDevice->token_hash);
        $decryptedCookieValue = Crypt::decryptString(rawurldecode($cookieValue));
        $rawTrustedDeviceToken = CookieValuePrefix::remove($decryptedCookieValue);
        $this->assertSame($trustedDevice->token_hash, hash('sha256', $rawTrustedDeviceToken));
        $this->assertSensitiveValuesAbsentFromTwoFactorRecords([
            self::SECRET,
            $cookieValue,
            $rawTrustedDeviceToken,
            $trustedDevice->token_hash,
        ]);

        $this->post('/logout')->assertRedirect('/');
        $this->travel(1)->minute();

        $this->withCookie($cookie->getName(), $cookieValue)
            ->post('/login', [
                'email' => 'trusted@example.test',
                'password' => 'correct horse battery staple',
            ])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $trustedDevice->refresh();
        $this->assertTrue($trustedDevice->expires_at->equalTo($originalExpiry));
        $this->assertNotNull($trustedDevice->last_used_at);
        $this->assertTrue($originalLastUsed === null || $trustedDevice->last_used_at->greaterThan($originalLastUsed));

        $this->post('/logout')->assertRedirect('/');
        $this->withCookie($cookie->getName(), $cookieValue)
            ->post('/login', [
                'email' => 'other-trusted@example.test',
                'password' => 'correct horse battery staple',
            ])->assertRedirect('/login/two-factor-challenge');
        $this->assertGuest();

        $trustedDevice->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->withCookie($cookie->getName(), $cookieValue)
            ->post('/login', [
                'email' => 'trusted@example.test',
                'password' => 'correct horse battery staple',
            ])->assertRedirect('/login/two-factor-challenge');
        $this->assertGuest();

        $trustedDevice->forceFill(['expires_at' => now()->addDays(30)])->save();
        $trustedDevice->delete();
        $this->withCookie($cookie->getName(), $cookieValue)
            ->post('/login', [
                'email' => 'trusted@example.test',
                'password' => 'correct horse battery staple',
            ])->assertRedirect('/login/two-factor-challenge');
        $this->assertGuest();
        $this->travelBack();
    }

    public function test_challenge_rate_limit_is_account_global_across_source_ips(): void
    {
        config([
            'rate_limits.two_factor_challenge_per_minute' => 2,
            'rate_limits.two_factor_challenge_account_per_hour' => 2,
            'rate_limits.two_factor_challenge_ip_per_minute' => 10,
        ]);
        $user = $this->createUser('Rate Limited User', 'rate-limited-2fa@example.test');
        $this->enableTwoFactor($user);

        $this->post('/login', [
            'email' => 'rate-limited-2fa@example.test',
            'password' => 'correct horse battery staple',
        ])->assertRedirect('/login/two-factor-challenge');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/login/two-factor-challenge', ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])
            ->post('/login/two-factor-challenge', ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.12'])
            ->post('/login/two-factor-challenge', ['code' => '000000'])
            ->assertTooManyRequests();
    }

    public function test_forged_or_stale_challenge_marker_cannot_complete_login(): void
    {
        $user = $this->createUser('Marker User', 'marker@example.test');
        $this->enableTwoFactor($user);

        $this->withSession([
            'auth.two_factor_challenge' => [
                'user_uid' => $user->uid,
                'created_at' => now()->getTimestamp(),
                'remember' => false,
                'nonce' => Str::random(64),
            ],
        ])->post('/login/two-factor-challenge', [
            'code' => $this->currentOtp(self::SECRET),
        ])->assertSessionHasErrors('code');

        $this->assertGuest();

        $this->post('/login', [
            'email' => 'marker@example.test',
            'password' => 'correct horse battery staple',
        ])->assertRedirect('/login/two-factor-challenge');

        $this->travel(6)->minutes();
        $this->get('/login/two-factor-challenge')->assertRedirect('/login');
        $this->post('/login/two-factor-challenge', [
            'code' => $this->currentOtp(self::SECRET),
        ])->assertSessionHasErrors('code');
    }

    public function test_password_reset_invalidates_a_pending_totp_challenge_authenticated_with_the_old_password(): void
    {
        $user = $this->createUser('Reset Challenge User', 'reset-challenge@example.test');
        $this->enableTwoFactor($user);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct horse battery staple',
        ])->assertRedirect('/login/two-factor-challenge');

        app(ResetUserPassword::class)->handle($user, 'new secure password');

        $this->post('/login/two-factor-challenge', [
            'code' => $this->currentOtp(self::SECRET),
            'remember_device' => '1',
        ])->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertFalse(session()->has(TwoFactorPendingChallenge::SESSION_KEY));
        $this->assertNull($user->refresh()->two_factor_last_used_timestep);
        $this->assertSame(0, TrustedDevice::query()->where('user_uid', $user->uid)->count());
    }

    public function test_password_reset_invalidates_a_pending_recovery_code_challenge_without_consuming_the_code(): void
    {
        $user = $this->createUser('Reset Recovery User', 'reset-recovery@example.test');
        $this->enableTwoFactor($user, recoveryCodes: ['ABCD2-EFGH3']);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'correct horse battery staple',
        ])->assertRedirect('/login/two-factor-challenge');

        app(ResetUserPassword::class)->handle($user, 'new secure password');

        $this->post('/login/two-factor-challenge', [
            'recovery_code' => 'ABCD2-EFGH3',
        ])->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertFalse(session()->has(TwoFactorPendingChallenge::SESSION_KEY));
        $recoveryCodes = $user->refresh()->two_factor_recovery_codes;
        $this->assertIsArray($recoveryCodes);
        $this->assertCount(1, $recoveryCodes);
        $this->assertTrue(Hash::check('ABCD2-EFGH3', $recoveryCodes[0]));
    }

    private function createUser(string $name, string $email): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('correct horse battery staple'),
        ]);
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        app(CreatePersonalWorkspaceForUser::class)->handle($user);

        return $user;
    }

    private function createTrustedDevice(User $user, string $token): TrustedDevice
    {
        return TrustedDevice::query()->forceCreate([
            'user_uid' => $user->uid,
            'token_hash' => hash('sha256', $token),
            'label' => 'Test browser',
            'user_agent_summary' => 'Test browser',
            'expires_at' => now()->addDays(30),
            'last_used_at' => null,
        ]);
    }

    /**
     * @param list<string> $recoveryCodes
     */
    private function enableTwoFactor(
        User $user,
        string $secret = self::SECRET,
        array $recoveryCodes = ['ABCD2-EFGH3', 'JKLM4-NPQR5'],
    ): void {
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map(
                static fn (string $code): string => Hash::make($code),
                $recoveryCodes,
            ),
            'two_factor_last_used_timestep' => null,
        ])->save();
    }

    /**
     * @param list<string> $values
     */
    private function assertSensitiveValuesAbsentFromTwoFactorRecords(array $values): void
    {
        $needles = array_values(array_filter(
            $values,
            static fn (string $value): bool => $value !== '',
        ));

        foreach (DomainEvent::query()->where('event_type', 'like', 'user.two_factor.%')->get() as $event) {
            $encodedPayload = json_encode($event->payload, JSON_THROW_ON_ERROR);
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $encodedPayload);
            }
        }

        foreach (AuditEntry::query()->where('action', 'like', 'user.two_factor.%')->get() as $audit) {
            $encodedMetadata = json_encode($audit->metadata, JSON_THROW_ON_ERROR);
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, $encodedMetadata);
            }
        }
    }

    private function currentOtp(string $secret): string
    {
        return (new Google2FA())->getCurrentOtp($secret);
    }
}
