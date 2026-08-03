<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Http\Middleware\RequireRecentSystemAdminTwoFactorConfirmation;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class SystemAdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private const string TWO_FACTOR_SECRET = 'JBSWY3DPEHPK3PXP';

    public function test_entering_administration_requires_a_live_second_factor_instead_of_the_password(): void
    {
        $admin = $this->createUser('System Admin', 'admin-step-up@example.test', true);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertRedirect('/admin/confirm-two-factor');

        $this->actingAs($admin)
            ->get('/admin/confirm-two-factor')
            ->assertOk()
            ->assertSee('Authentication code')
            ->assertSee('Use a recovery code')
            ->assertDontSee('name="password"', escape: false)
            ->assertDontSee('name="remember_device"', escape: false);
    }

    public function test_system_admin_can_list_and_create_a_verified_login_user_with_actor_traceability(): void
    {
        $admin = $this->createUser('System Admin', 'admin@example.test', true);
        $existing = $this->createUser('Existing User', 'existing@example.test');
        $sourceUrl = config('app.source_url');
        $this->assertIsString($sourceUrl);

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminTwoFactorConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('User administration')
            ->assertSee($admin->name)
            ->assertSee($existing->name)
            ->assertSee('Deployment settings')
            ->assertSee($sourceUrl)
            ->assertDontSee('APP_KEY');

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminTwoFactorConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->post('/admin/users', [
                'name' => 'Created User',
                'email' => 'CREATED@example.test',
                'password' => 'correct horse battery staple',
                'password_confirmation' => 'correct horse battery staple',
                'is_system_admin' => '1',
            ])
            ->assertRedirect('/admin/users')
            ->assertSessionHas('status', 'User created.');

        $created = User::query()->where('email', 'created@example.test')->sole();
        $this->assertSame('Created User', $created->name);
        $this->assertFalse($created->is_system_admin);
        $this->assertNotNull($created->email_verified_at);
        $this->assertTrue(Hash::check('correct horse battery staple', $created->password));
        $this->assertSame(1, Workspace::query()->where('personal_owner_uid', $created->uid)->count());

        $event = DomainEvent::query()
            ->where('event_type', 'user.created')
            ->where('aggregate_uid', $created->uid)
            ->sole();
        $this->assertSame($admin->uid, $event->payload['created_by_user_uid']);
        $this->assertArrayNotHasKey('password', $event->payload);

        $audit = AuditEntry::query()
            ->where('action', 'user.created')
            ->where('auditable_uid', $created->uid)
            ->sole();
        $this->assertSame($admin->uid, $audit->actor_user_uid);
        $this->assertArrayNotHasKey('password', $audit->metadata);
    }

    public function test_system_admin_must_confirm_two_factor_before_user_administration(): void
    {
        $admin = $this->createUser('System Admin', 'admin@example.test', true);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertRedirect('/admin/confirm-two-factor');

        $this->actingAs($admin)
            ->post('/admin/users', [
                'name' => 'Blocked User',
                'email' => 'blocked@example.test',
                'password' => 'correct horse battery staple',
                'password_confirmation' => 'correct horse battery staple',
            ])
            ->assertRedirect('/admin/confirm-two-factor');

        $this->assertSame(1, User::query()->count());

        $this->actingAs($admin)
            ->get('/admin/confirm-two-factor')
            ->assertOk()
            ->assertSee('Confirm admin access')
            ->assertSee('Authentication code')
            ->assertSee('Use a recovery code')
            ->assertDontSee('name="password"', escape: false);
    }

    public function test_system_admin_two_factor_confirmation_rejects_an_invalid_code_and_allows_a_fresh_totp(): void
    {
        $admin = $this->createUser('System Admin', 'admin@example.test', true);
        $validCode = $this->currentTotp();
        $invalidCode = $validCode === '000000' ? '000001' : '000000';

        $this->actingAs($admin)
            ->withSession(['url.intended' => route('admin.users.index')])
            ->post('/admin/confirm-two-factor', ['code' => $invalidCode])
            ->assertSessionHasErrors('code')
            ->assertSessionMissing('_old_input.code')
            ->assertSessionMissing(RequireRecentSystemAdminTwoFactorConfirmation::SESSION_KEY);

        $sessionId = $this->app['session']->getId();
        $this->actingAs($admin)
            ->withSession(['url.intended' => '/admin/settings'])
            ->post('/admin/confirm-two-factor', ['code' => $validCode])
            ->assertRedirect('/admin/settings')
            ->assertSessionHas(RequireRecentSystemAdminTwoFactorConfirmation::SESSION_KEY);
        $this->assertNotSame($sessionId, $this->app['session']->getId());

        $this->actingAs($admin)
            ->post('/admin/confirm-two-factor', ['code' => $validCode])
            ->assertSessionHasErrors('code');

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminTwoFactorConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->get('/admin/users')
            ->assertOk();
    }

    public function test_system_admin_two_factor_confirmation_has_an_account_global_hourly_limit(): void
    {
        config([
            'rate_limits.admin_two_factor_per_minute' => 10,
            'rate_limits.admin_two_factor_account_per_hour' => 2,
            'rate_limits.admin_two_factor_ip_per_minute' => 10,
        ]);
        $admin = $this->createUser('Rate Limited Admin', 'rate-limited-admin@example.test', true);

        $this->actingAs($admin);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->post('/admin/confirm-two-factor', ['code' => 'not-a-code'])
            ->assertSessionHasErrors('code');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.21'])
            ->post('/admin/confirm-two-factor', ['code' => 'not-a-code'])
            ->assertSessionHasErrors('code');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.22'])
            ->post('/admin/confirm-two-factor', ['code' => 'not-a-code'])
            ->assertTooManyRequests();
    }

    public function test_system_admin_recovery_code_confirmation_is_single_use_and_discards_external_redirects(): void
    {
        $admin = $this->createUser('System Admin', 'admin@example.test', true);

        $this->actingAs($admin)
            ->withSession(['url.intended' => 'https://evil.example/phish'])
            ->post('/admin/confirm-two-factor', ['recovery_code' => 'ABCD2-EFGH3'])
            ->assertRedirect('/admin/users')
            ->assertSessionMissing('url.intended')
            ->assertSessionHas(RequireRecentSystemAdminTwoFactorConfirmation::SESSION_KEY);

        $this->assertSame([], $admin->refresh()->two_factor_recovery_codes);

        $this->actingAs($admin)
            ->post('/admin/confirm-two-factor', ['recovery_code' => 'ABCD2-EFGH3'])
            ->assertSessionHasErrors('code')
            ->assertSessionMissing('_old_input.recovery_code');
    }

    public function test_system_admin_two_factor_confirmation_expires_and_rejects_future_timestamps(): void
    {
        config(['auth.admin_two_factor_timeout' => 900]);

        $admin = $this->createUser('System Admin', 'admin@example.test', true);

        $this->actingAs($admin)
            ->withSession([
                RequireRecentSystemAdminTwoFactorConfirmation::SESSION_KEY => now()->subSeconds(901)->getTimestamp(),
            ])
            ->get('/admin/users')
            ->assertRedirect('/admin/confirm-two-factor');

        $this->actingAs($admin)
            ->withSession([
                RequireRecentSystemAdminTwoFactorConfirmation::SESSION_KEY => now()->addSecond()->getTimestamp(),
            ])
            ->get('/admin/users')
            ->assertRedirect('/admin/confirm-two-factor');

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminTwoFactorConfirmation::SESSION_KEY => ['unexpected']])
            ->get('/admin/users')
            ->assertRedirect('/admin/confirm-two-factor');
    }

    public function test_unenrolled_system_admin_cannot_use_a_forged_fresh_confirmation_marker(): void
    {
        $admin = $this->createUser('System Admin', 'unenrolled-admin@example.test', true);
        $admin->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminTwoFactorConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->get('/admin/users')
            ->assertRedirect('/settings/two-factor');
    }

    public function test_non_admin_cannot_view_or_forge_user_administration_requests(): void
    {
        $user = $this->createUser('Normal User', 'user@example.test');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('User administration')
            ->assertDontSee('/admin/users', false);

        $this->actingAs($user)
            ->get('/admin/users')
            ->assertForbidden();

        $this->actingAs($user)
            ->get('/admin/confirm-two-factor')
            ->assertForbidden();

        $this->actingAs($user)
            ->post('/admin/confirm-two-factor', ['code' => '123456'])
            ->assertForbidden();

        $this->actingAs($user)
            ->post('/admin/users', [
                'name' => 'Forged User',
                'email' => 'forged@example.test',
                'password' => 'correct horse battery staple',
                'password_confirmation' => 'correct horse battery staple',
            ])
            ->assertForbidden();

        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'user.created')->count());
    }

    public function test_system_admin_two_factor_confirmation_middleware_fails_closed_for_non_admins(): void
    {
        $user = $this->createUser('Normal User', 'middleware-user@example.test');
        $request = Request::create('/admin/users');
        $request->setUserResolver(static fn (): User => $user);

        $this->expectException(HttpException::class);

        app(RequireRecentSystemAdminTwoFactorConfirmation::class)->handle(
            $request,
            static fn () => response('passed'),
        );
    }

    public function test_system_admin_user_creation_validates_password_and_duplicate_identity(): void
    {
        $admin = $this->createUser('System Admin', 'admin@example.test', true);
        $this->createUser('Existing User', 'existing@example.test');

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminTwoFactorConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->post('/admin/users', [
                'name' => '',
                'email' => 'not an email',
                'password' => 'short',
                'password_confirmation' => 'different',
            ])
            ->assertSessionHasErrors(['name', 'email', 'password']);

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminTwoFactorConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->post('/admin/users', [
                'name' => 'Duplicate User',
                'email' => 'EXISTING@example.test',
                'password' => 'correct horse battery staple',
                'password_confirmation' => 'correct horse battery staple',
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame(2, User::query()->count());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'user.created')->count());
    }

    private function createUser(string $name, string $email, bool $isSystemAdmin = false): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);

        if ($isSystemAdmin) {
            $user->forceFill([
                'is_system_admin' => true,
                'two_factor_secret' => self::TWO_FACTOR_SECRET,
                'two_factor_confirmed_at' => now(),
                'two_factor_recovery_codes' => [Hash::make('ABCD2-EFGH3')],
            ])->save();
        }

        return $user;
    }

    private function currentTotp(): string
    {
        return app(Google2FA::class)->getCurrentOtp(self::TWO_FACTOR_SECRET);
    }
}
