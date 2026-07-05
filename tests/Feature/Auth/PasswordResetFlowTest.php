<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Application\Identity\CreatePersonalWorkspaceForUser;
use App\Application\Mcp\McpAccessTokenIssuer;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\McpAccessToken;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Auth\Notifications\ResetPassword as BaseResetPasswordNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use ReflectionClass;
use Tests\TestCase;

final class PasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    private const string RESET_LINK_STATUS = 'If the address exists, a password reset link has been sent.';

    public function test_forgot_password_response_is_uniform_and_sends_notification_only_for_real_users(): void
    {
        Notification::fake();
        $user = $this->createUser('Reset User', 'reset@example.test', 'old secure password');

        $realResponse = $this->post('/forgot-password', ['email' => ' RESET@example.test '])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status', self::RESET_LINK_STATUS);

        $unknownResponse = $this->post('/forgot-password', ['email' => 'unknown@example.test'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status', self::RESET_LINK_STATUS);

        $this->assertSame($realResponse->getStatusCode(), $unknownResponse->getStatusCode());
        Notification::assertSentTo($user, ResetPasswordNotification::class);
        Notification::assertCount(1);
    }

    public function test_password_reset_notification_uses_the_configured_app_origin(): void
    {
        Notification::fake();
        config([
            'app.url' => 'https://app.example.internal',
            'app.artifact_url' => 'https://artifacts.example.internal',
        ]);
        $user = $this->createUser('Reset User', 'reset-origin@example.test', 'old secure password');

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status', self::RESET_LINK_STATUS);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user): bool {
            $mail = $notification->toMail($user);

            return str_starts_with($mail->actionUrl, 'https://app.example.internal/reset-password/')
                && str_contains($mail->actionUrl, 'email=reset-origin%40example.test')
                && !str_contains($mail->actionUrl, 'artifacts.example.internal');
        });
    }

    public function test_password_reset_token_is_single_use_and_records_safe_traceability(): void
    {
        $user = $this->createUser('Reset User', 'single-use@example.test', 'old secure password');
        $oldRememberToken = (string) $user->remember_token;
        $user->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [
                Hash::make('RECOVERY-1'),
            ],
            'two_factor_last_used_timestep' => 123,
        ])->save();
        TrustedDevice::query()->forceCreate([
            'user_uid' => $user->uid,
            'token_hash' => hash('sha256', 'raw trusted token'),
            'label' => 'Reset test browser',
            'user_agent_summary' => 'Reset test browser',
            'expires_at' => now()->addDays(30),
            'last_used_at' => now(),
        ]);
        $mcpToken = app(McpAccessTokenIssuer::class)->issue(
            principal: $user,
            name: 'Reset revoked token',
            scopes: [McpAccessTokenIssuer::SCOPE_SEARCH],
            expiresAt: now()->addHour(),
        )->accessToken;
        DB::table('sessions')->insert([
            'id' => 'session-to-delete',
            'user_id' => $user->uid,
            'ip_address' => '203.0.113.20',
            'user_agent' => 'Test Browser',
            'payload' => 'payload',
            'last_activity' => time(),
        ]);
        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new secure password',
            'password_confirmation' => 'new secure password',
        ])
            ->assertRedirect('/login')
            ->assertSessionHas('status', 'Your password has been reset. You can sign in with the new password.');

        $user->refresh();
        $this->assertTrue(Hash::check('new secure password', $user->password));
        $this->assertNotSame($oldRememberToken, $user->remember_token);
        $this->assertTrue($user->hasEnabledTwoFactor());
        $this->assertSame('JBSWY3DPEHPK3PXP', $user->two_factor_secret);
        $this->assertSame(123, $user->two_factor_last_used_timestep);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->uid)->count());
        $this->assertSame(0, TrustedDevice::query()->where('user_uid', $user->uid)->count());
        $this->assertNotNull($mcpToken->refresh()->revoked_at);
        $this->assertSame(1, McpAccessToken::query()->where('principal_user_uid', $user->uid)->count());
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseHas('domain_events', [
            'event_type' => 'user.password_reset',
            'aggregate_uid' => $user->uid,
        ]);

        $event = DomainEvent::query()
            ->where('event_type', 'user.password_reset')
            ->sole();
        $auditEntry = AuditEntry::query()
            ->where('action', 'user.password_reset')
            ->sole();

        $this->assertArrayNotHasKey('password', $event->payload);
        $this->assertArrayNotHasKey('token', $event->payload);
        $this->assertArrayNotHasKey('email', $event->payload);
        $this->assertArrayNotHasKey('password', $auditEntry->metadata);
        $this->assertArrayNotHasKey('token', $auditEntry->metadata);
        $this->assertArrayNotHasKey('email', $auditEntry->metadata);
        $this->assertSame(1, $auditEntry->metadata['trusted_devices_revoked']);
        $this->assertSame(1, $auditEntry->metadata['mcp_tokens_revoked']);
        $this->assertSame(
            1,
            DomainEvent::query()
                ->where('event_type', 'mcp_token.revoked')
                ->where('aggregate_uid', $mcpToken->uid)
                ->count(),
        );

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'another secure password',
            'password_confirmation' => 'another secure password',
        ])
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('new secure password', $user->refresh()->password));
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'user.password_reset')->count());
    }

    public function test_expired_password_reset_token_is_rejected(): void
    {
        config(['auth.passwords.users.expire' => 1]);
        $user = $this->createUser('Reset User', 'expired-reset@example.test', 'old secure password');
        $token = Password::broker()->createToken($user);

        $this->travel(2)->minutes();

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new secure password',
            'password_confirmation' => 'new secure password',
        ])
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('old secure password', $user->refresh()->password));
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'user.password_reset')->count());
    }

    public function test_password_reset_submit_response_is_uniform_for_unknown_email_and_bad_token(): void
    {
        $user = $this->createUser('Reset User', 'known-reset@example.test', 'old secure password');

        $knownResponse = $this->from('/reset-password/bad-token?email=known-reset@example.test')
            ->post('/reset-password', [
                'token' => 'bad-token',
                'email' => $user->email,
                'password' => 'new secure password',
                'password_confirmation' => 'new secure password',
            ])
            ->assertRedirect('/reset-password/bad-token?email=known-reset@example.test')
            ->assertSessionHasErrors('email');

        $knownErrorBag = session('errors');
        $this->assertInstanceOf(ViewErrorBag::class, $knownErrorBag);
        $knownErrors = $knownErrorBag->get('email');

        $unknownResponse = $this->from('/reset-password/bad-token?email=unknown@example.test')
            ->post('/reset-password', [
                'token' => 'bad-token',
                'email' => 'unknown@example.test',
                'password' => 'new secure password',
                'password_confirmation' => 'new secure password',
            ])
            ->assertRedirect('/reset-password/bad-token?email=unknown@example.test')
            ->assertSessionHasErrors('email');

        $unknownErrorBag = session('errors');
        $this->assertInstanceOf(ViewErrorBag::class, $unknownErrorBag);
        $unknownErrors = $unknownErrorBag->get('email');
        $this->assertSame($knownResponse->getStatusCode(), $unknownResponse->getStatusCode());
        $this->assertSame($knownErrors, $unknownErrors);
        $this->assertSame(['Password reset link is invalid or has expired.'], $unknownErrors);
        $this->assertTrue(Hash::check('old secure password', $user->refresh()->password));
    }

    public function test_short_password_is_rejected_without_consuming_the_token(): void
    {
        $user = $this->createUser('Reset User', 'short-reset-flow@example.test', 'old secure password');
        $token = Password::broker()->createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'too-short',
            'password_confirmation' => 'too-short',
        ])
            ->assertRedirect()
            ->assertSessionHasErrors('password');

        $this->assertTrue(Password::broker()->tokenExists($user, $token));
        $this->assertTrue(Hash::check('old secure password', $user->refresh()->password));
    }

    public function test_forgot_password_route_is_rate_limited(): void
    {
        config(['rate_limits.password_resets_per_hour' => 2]);

        $this->post('/forgot-password', ['email' => 'first@example.test'])
            ->assertSessionHas('status', self::RESET_LINK_STATUS);
        $this->post('/forgot-password', ['email' => 'first@example.test'])
            ->assertSessionHas('status', self::RESET_LINK_STATUS);
        $this->post('/forgot-password', ['email' => 'first@example.test'])
            ->assertTooManyRequests();
    }

    public function test_password_reset_routes_are_not_exposed_on_the_artifact_runtime(): void
    {
        config(['app.runtime_role' => 'artifact-host']);

        $this->get('/forgot-password')->assertNotFound();
        $this->post('/forgot-password', ['email' => 'user@example.test'])->assertNotFound();
        $this->get('/reset-password/token')->assertNotFound();
        $this->post('/reset-password', [
            'token' => 'token',
            'email' => 'user@example.test',
            'password' => 'new secure password',
            'password_confirmation' => 'new secure password',
        ])->assertNotFound();
    }

    public function test_reset_notification_is_queued_and_encrypted_to_avoid_timing_or_token_payload_leaks(): void
    {
        Queue::fake();
        $user = $this->createUser('Reset User', 'no-queue-token@example.test', 'old secure password');

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status', self::RESET_LINK_STATUS);

        Queue::assertPushed(SendQueuedNotifications::class, function (SendQueuedNotifications $job) use ($user): bool {
            $notifiable = $job->notifiables->first();

            return $notifiable instanceof User
                && $notifiable->uid === $user->uid
                && $job->notification instanceof ResetPasswordNotification
                && $job->shouldBeEncrypted === true;
        });
        $interfaces = class_implements(ResetPasswordNotification::class);
        $this->assertIsArray($interfaces);
        $this->assertContains(ShouldQueue::class, $interfaces);
        $this->assertContains(ShouldBeEncrypted::class, $interfaces);
    }

    public function test_reset_notification_extends_laravel_reset_notification(): void
    {
        $parent = (new ReflectionClass(ResetPasswordNotification::class))->getParentClass();

        $this->assertNotFalse($parent);
        $this->assertSame(BaseResetPasswordNotification::class, $parent->getName());
    }

    private function createUser(string $name, string $email, string $password): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        app(CreatePersonalWorkspaceForUser::class)->handle($user);

        return $user;
    }
}
