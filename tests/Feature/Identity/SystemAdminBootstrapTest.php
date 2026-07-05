<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\BootstrapSystemAdmin;
use App\Domain\DomainRuleViolation;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class SystemAdminBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_bootstraps_a_system_admin_user_with_traceability(): void
    {
        $user = app(BootstrapSystemAdmin::class)->handle(
            name: 'Ada Admin',
            email: '  ADMIN@Example.TEST ',
            password: 'correct horse battery staple',
        );

        $this->assertSame('admin@example.test', $user->email);
        $this->assertTrue($user->is_system_admin);
        $this->assertTrue(Hash::check('correct horse battery staple', $user->password));
        $this->assertSame(1, Workspace::query()->where('personal_owner_uid', $user->uid)->count());

        $event = DomainEvent::query()
            ->where('event_type', 'user.system_admin.bootstrapped')
            ->sole();

        $this->assertSame('user', $event->aggregate_type);
        $this->assertSame($user->uid, $event->aggregate_uid);
        $this->assertSame($user->uid, $event->payload['user_uid']);
        $this->assertSame('admin@example.test', $event->payload['email']);
        $this->assertArrayNotHasKey('password', $event->payload);

        $auditEntry = AuditEntry::query()
            ->where('action', 'user.system_admin.bootstrapped')
            ->sole();

        $this->assertSame($event->uid, $auditEntry->event_uid);
        $this->assertNull($auditEntry->actor_user_uid);
        $this->assertSame('user', $auditEntry->auditable_type);
        $this->assertSame($user->uid, $auditEntry->auditable_uid);
        $this->assertSame('System admin bootstrapped.', $auditEntry->summary);
        $this->assertSame('admin@example.test', $auditEntry->metadata['email']);
        $this->assertArrayNotHasKey('password', $auditEntry->metadata);
    }

    public function test_bootstrap_is_idempotent_for_an_existing_system_admin(): void
    {
        $firstUser = app(BootstrapSystemAdmin::class)->handle(
            name: 'Ada Admin',
            email: 'admin@example.test',
            password: 'correct horse battery staple',
        );
        $secondUser = app(BootstrapSystemAdmin::class)->handle(
            name: 'Renamed Admin',
            email: 'ADMIN@example.test',
            password: 'different long password',
        );

        $this->assertSame($firstUser->uid, $secondUser->uid);
        $this->assertSame('Ada Admin', $secondUser->name);
        $this->assertTrue(Hash::check('correct horse battery staple', $secondUser->password));
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, Workspace::query()->where('personal_owner_uid', $firstUser->uid)->count());
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'user.system_admin.bootstrapped')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'user.system_admin.bootstrapped')->count());
    }

    public function test_it_promotes_an_existing_user_without_resetting_their_password(): void
    {
        $user = User::query()->create([
            'name' => 'Existing User',
            'email' => 'existing@example.test',
            'password' => Hash::make('existing secure password'),
        ]);

        $promotedUser = app(BootstrapSystemAdmin::class)->handle(
            name: 'Ignored Name',
            email: 'existing@example.test',
            password: 'new secure password',
        );

        $this->assertSame($user->uid, $promotedUser->uid);
        $this->assertTrue($promotedUser->is_system_admin);
        $this->assertSame('Existing User', $promotedUser->name);
        $this->assertTrue(Hash::check('existing secure password', $promotedUser->password));
        $this->assertSame(1, Workspace::query()->where('personal_owner_uid', $promotedUser->uid)->count());

        $event = DomainEvent::query()
            ->where('event_type', 'user.system_admin.promoted')
            ->sole();

        $this->assertSame($user->uid, $event->aggregate_uid);
        $this->assertSame('existing@example.test', $event->payload['email']);
    }

    public function test_it_rejects_invalid_bootstrap_email(): void
    {
        try {
            app(BootstrapSystemAdmin::class)->handle(
                name: 'Invalid Admin',
                email: 'not an email',
                password: 'correct horse battery staple',
            );
            $this->fail('Expected an invalid bootstrap email to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('System admin email must be a valid email address.', $exception->getMessage());
        }

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, DomainEvent::query()->count());
        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_it_rejects_short_bootstrap_passwords_for_new_admins(): void
    {
        try {
            app(BootstrapSystemAdmin::class)->handle(
                name: 'Short Password Admin',
                email: 'admin@example.test',
                password: 'too-short',
            );
            $this->fail('Expected a short bootstrap password to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('System admin password must be at least 12 characters.', $exception->getMessage());
        }

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, DomainEvent::query()->count());
        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_system_admin_can_be_bootstrapped_from_the_console_without_printing_the_password(): void
    {
        $exitCode = Artisan::call('artifactflow:bootstrap-admin', [
            '--name' => 'Console Admin',
            '--email' => 'console@example.test',
            '--password' => 'correct horse battery staple',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('System admin ready: console@example.test', $output);
        $this->assertStringNotContainsString('correct horse battery staple', $output);

        $user = User::query()->where('email', 'console@example.test')->sole();

        $this->assertTrue($user->is_system_admin);
        $this->assertTrue(Hash::check('correct horse battery staple', $user->password));
    }
}
