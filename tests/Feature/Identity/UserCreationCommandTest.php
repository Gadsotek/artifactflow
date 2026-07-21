<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\CreateUser;
use App\Domain\DomainRuleViolation;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UserCreationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_login_user_with_traceability_and_personal_workspace(): void
    {
        $user = app(CreateUser::class)->handle(
            name: '  Created User  ',
            email: '  CREATED@Example.TEST ',
            password: 'correct horse battery staple',
        );

        $this->assertSame('Created User', $user->name);
        $this->assertSame('created@example.test', $user->email);
        $this->assertFalse($user->is_system_admin);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('correct horse battery staple', $user->password));
        $this->assertSame(1, Workspace::query()->where('personal_owner_uid', $user->uid)->count());

        $event = DomainEvent::query()
            ->where('event_type', 'user.created')
            ->sole();

        $this->assertSame('user', $event->aggregate_type);
        $this->assertSame($user->uid, $event->aggregate_uid);
        $this->assertSame($user->uid, $event->payload['user_uid']);
        $this->assertSame('created@example.test', $event->payload['email']);
        $this->assertArrayNotHasKey('password', $event->payload);

        $auditEntry = AuditEntry::query()
            ->where('action', 'user.created')
            ->sole();

        $this->assertSame($event->uid, $auditEntry->event_uid);
        $this->assertNull($auditEntry->actor_user_uid);
        $this->assertSame('user', $auditEntry->auditable_type);
        $this->assertSame($user->uid, $auditEntry->auditable_uid);
        $this->assertSame('User created.', $auditEntry->summary);
        $this->assertSame('created@example.test', $auditEntry->metadata['email']);
        $this->assertArrayNotHasKey('password', $auditEntry->metadata);
    }

    public function test_user_creation_rejects_duplicate_emails_without_resetting_the_password(): void
    {
        $user = app(CreateUser::class)->handle(
            name: 'Created User',
            email: 'created@example.test',
            password: 'correct horse battery staple',
        );

        try {
            app(CreateUser::class)->handle(
                name: 'Duplicate User',
                email: 'CREATED@example.test',
                password: 'different secure password',
            );
            $this->fail('Expected duplicate user email to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('A user with this email already exists.', $exception->getMessage());
        }

        $this->assertSame(1, User::query()->count());
        $this->assertTrue(Hash::check('correct horse battery staple', $user->refresh()->password));
        $this->assertSame(1, DomainEvent::query()->where('event_type', 'user.created')->count());
        $this->assertSame(1, AuditEntry::query()->where('action', 'user.created')->count());
    }

    public function test_user_creation_maps_a_concurrent_email_unique_violation_to_the_domain_error(): void
    {
        $connection = config('database.connections.pgsql');
        $this->assertIsArray($connection);
        config(['database.connections.concurrent_user_create' => $connection]);
        $insertedCompetitor = false;

        DB::listen(function (QueryExecuted $query) use (&$insertedCompetitor): void {
            $sql = strtolower($query->sql);
            if (
                $insertedCompetitor
                || !str_contains($sql, 'from "users"')
                || !str_contains($sql, '"email"')
                || !in_array('racing@example.test', $query->bindings, true)
            ) {
                return;
            }

            $insertedCompetitor = true;
            DB::connection('concurrent_user_create')->table('users')->insert([
                'uid' => (string) Str::ulid(),
                'name' => 'Concurrent Winner',
                'email' => 'racing@example.test',
                'email_verified_at' => now(),
                'password' => Hash::make('correct horse battery staple'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            app(CreateUser::class)->handle(
                name: 'Concurrent Loser',
                email: 'racing@example.test',
                password: 'correct horse battery staple',
            );
            $this->fail('Expected the concurrent duplicate email to be rejected.');
        } catch (DomainRuleViolation $exception) {
            $this->assertSame('A user with this email already exists.', $exception->getMessage());
        } finally {
            if ($insertedCompetitor) {
                DB::connection('concurrent_user_create')
                    ->table('users')
                    ->where('email', 'racing@example.test')
                    ->delete();
            }

            DB::purge('concurrent_user_create');
        }

        $this->assertTrue($insertedCompetitor);
    }

    public function test_user_creation_rejects_invalid_inputs_without_trace_events(): void
    {
        foreach ([
            ['name' => '', 'email' => 'user@example.test', 'password' => 'correct horse battery staple', 'message' => 'User name must not be blank.'],
            ['name' => 'User', 'email' => 'not an email', 'password' => 'correct horse battery staple', 'message' => 'User email must be a valid email address.'],
            ['name' => 'User', 'email' => 'user@example.test', 'password' => 'too-short', 'message' => 'User password must be at least 12 characters.'],
        ] as $case) {
            try {
                app(CreateUser::class)->handle(
                    name: $case['name'],
                    email: $case['email'],
                    password: $case['password'],
                );
                $this->fail('Expected invalid user input to be rejected.');
            } catch (DomainRuleViolation $exception) {
                $this->assertSame($case['message'], $exception->getMessage());
            }
        }

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, DomainEvent::query()->count());
        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_user_can_be_created_from_the_console_without_printing_the_password(): void
    {
        $exitCode = Artisan::call('artifactflow:create-user', [
            '--name' => 'Console User',
            '--email' => 'console.user@example.test',
            '--password' => 'correct horse battery staple',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('User ready: console.user@example.test', $output);
        $this->assertStringNotContainsString('correct horse battery staple', $output);

        $user = User::query()->where('email', 'console.user@example.test')->sole();

        $this->assertFalse($user->is_system_admin);
        $this->assertTrue(Hash::check('correct horse battery staple', $user->password));
    }

    public function test_console_user_creation_can_use_configured_password_fallback(): void
    {
        config(['app.create_user_password' => 'configured secure password']);

        $exitCode = Artisan::call('artifactflow:create-user', [
            '--name' => 'Configured User',
            '--email' => 'configured@example.test',
        ]);

        $this->assertSame(0, $exitCode);

        $user = User::query()->where('email', 'configured@example.test')->sole();

        $this->assertTrue(Hash::check('configured secure password', $user->password));
        $this->assertStringNotContainsString('configured secure password', Artisan::output());
    }

    public function test_console_user_creation_reads_a_password_from_a_one_shot_secret_file(): void
    {
        $secretFile = storage_path('framework/testing/create-user-password-' . Str::random(12));
        file_put_contents($secretFile, "password from secret file\n");
        putenv('ARTIFACTFLOW_CREATE_USER_PASSWORD_FILE=' . $secretFile);

        try {
            $exitCode = Artisan::call('artifactflow:create-user', [
                '--name' => 'File Password User',
                '--email' => 'file-password-user@example.test',
            ]);
        } finally {
            putenv('ARTIFACTFLOW_CREATE_USER_PASSWORD_FILE');
            unlink($secretFile);
        }

        $this->assertSame(0, $exitCode);
        $user = User::query()->where('email', 'file-password-user@example.test')->sole();
        $this->assertTrue(Hash::check('password from secret file', $user->password));
    }
}
