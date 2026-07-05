<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Http\Middleware\RequireRecentSystemAdminPasswordConfirmation;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class SystemAdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_admin_can_list_and_create_a_verified_login_user_with_actor_traceability(): void
    {
        $admin = $this->createUser('System Admin', 'admin@example.test', true);
        $existing = $this->createUser('Existing User', 'existing@example.test');
        $sourceUrl = config('app.source_url');
        $this->assertIsString($sourceUrl);

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->get('/admin/users')
            ->assertOk()
            ->assertSee('User administration')
            ->assertSee($admin->name)
            ->assertSee($existing->name)
            ->assertSee('Deployment settings')
            ->assertSee($sourceUrl)
            ->assertDontSee('APP_KEY');

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
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

    public function test_system_admin_must_confirm_password_before_user_administration(): void
    {
        $admin = $this->createUser('System Admin', 'admin@example.test', true);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertRedirect('/admin/confirm-password');

        $this->actingAs($admin)
            ->post('/admin/users', [
                'name' => 'Blocked User',
                'email' => 'blocked@example.test',
                'password' => 'correct horse battery staple',
                'password_confirmation' => 'correct horse battery staple',
            ])
            ->assertRedirect('/admin/confirm-password');

        $this->assertSame(1, User::query()->count());

        $this->actingAs($admin)
            ->get('/admin/confirm-password')
            ->assertOk()
            ->assertSee('Confirm admin access');
    }

    public function test_system_admin_password_confirmation_rejects_wrong_password_and_allows_recent_confirmation(): void
    {
        $admin = $this->createUser('System Admin', 'admin@example.test', true);

        $this->actingAs($admin)
            ->withSession(['url.intended' => route('admin.users.index')])
            ->post('/admin/confirm-password', ['password' => 'wrong password'])
            ->assertSessionHasErrors('password')
            ->assertSessionMissing(RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY);

        $this->actingAs($admin)
            ->withSession(['url.intended' => '/admin/settings'])
            ->post('/admin/confirm-password', ['password' => 'password'])
            ->assertRedirect('/admin/settings')
            ->assertSessionHas(RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY);

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->get('/admin/users')
            ->assertOk();
    }

    public function test_system_admin_password_confirmation_discards_external_intended_redirects(): void
    {
        $admin = $this->createUser('System Admin', 'admin@example.test', true);

        $this->actingAs($admin)
            ->withSession(['url.intended' => 'https://evil.example/phish'])
            ->post('/admin/confirm-password', ['password' => 'password'])
            ->assertRedirect('/admin/users')
            ->assertSessionMissing('url.intended')
            ->assertSessionHas(RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY);
    }

    public function test_system_admin_password_confirmation_expires(): void
    {
        config(['auth.admin_password_timeout' => 900]);

        $admin = $this->createUser('System Admin', 'admin@example.test', true);

        $this->actingAs($admin)
            ->withSession([
                RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY => now()->subSeconds(901)->getTimestamp(),
            ])
            ->get('/admin/users')
            ->assertRedirect('/admin/confirm-password');
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
            ->get('/admin/confirm-password')
            ->assertForbidden();

        $this->actingAs($user)
            ->post('/admin/confirm-password', ['password' => 'password'])
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

    public function test_system_admin_password_confirmation_middleware_fails_closed_for_non_admins(): void
    {
        $user = $this->createUser('Normal User', 'middleware-user@example.test');
        $request = Request::create('/admin/users');
        $request->setUserResolver(static fn (): User => $user);

        $this->expectException(HttpException::class);

        app(RequireRecentSystemAdminPasswordConfirmation::class)->handle(
            $request,
            static fn () => response('passed'),
        );
    }

    public function test_system_admin_user_creation_validates_password_and_duplicate_identity(): void
    {
        $admin = $this->createUser('System Admin', 'admin@example.test', true);
        $this->createUser('Existing User', 'existing@example.test');

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
            ->post('/admin/users', [
                'name' => '',
                'email' => 'not an email',
                'password' => 'short',
                'password_confirmation' => 'different',
            ])
            ->assertSessionHasErrors(['name', 'email', 'password']);

        $this->actingAs($admin)
            ->withSession([RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY => now()->getTimestamp()])
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
                'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
                'two_factor_confirmed_at' => now(),
                'two_factor_recovery_codes' => [Hash::make('ABCD2-EFGH3')],
            ])->save();
        }

        return $user;
    }
}
