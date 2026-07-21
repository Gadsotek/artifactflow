<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Application\Identity\CreatePersonalWorkspaceForUser;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PasswordResetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_console_password_reset_rehashes_password_invalidates_sessions_and_records_safe_traceability(): void
    {
        $user = $this->createUser('Reset User', 'reset@example.test', 'old secure password');
        $oldRememberToken = (string) $user->remember_token;
        DB::table('sessions')->insert([
            'id' => 'existing-session-id',
            'user_id' => $user->uid,
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Test Browser',
            'payload' => 'payload',
            'last_activity' => time(),
        ]);

        $exitCode = Artisan::call('artifactflow:reset-password', [
            '--email' => ' RESET@example.test ',
            '--password' => 'new secure password',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Password reset for reset@example.test', $output);
        $this->assertStringNotContainsString('new secure password', $output);

        $user->refresh();
        $this->assertTrue(Hash::check('new secure password', $user->password));
        $this->assertNotSame($oldRememberToken, $user->remember_token);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->uid)->count());
        $this->assertFalse(Auth::attempt(['email' => $user->email, 'password' => 'old secure password']));
        $this->assertTrue(Auth::attempt(['email' => $user->email, 'password' => 'new secure password']));
        Auth::logout();

        $event = DomainEvent::query()
            ->where('event_type', 'user.password_reset')
            ->sole();

        $this->assertSame('user', $event->aggregate_type);
        $this->assertSame($user->uid, $event->aggregate_uid);
        $this->assertSame($user->uid, $event->payload['user_uid']);
        $this->assertArrayNotHasKey('email', $event->payload);
        $this->assertArrayNotHasKey('password', $event->payload);
        $this->assertArrayNotHasKey('token', $event->payload);

        $auditEntry = AuditEntry::query()
            ->where('action', 'user.password_reset')
            ->sole();

        $this->assertNull($auditEntry->actor_user_uid);
        $this->assertSame('user', $auditEntry->auditable_type);
        $this->assertSame($user->uid, $auditEntry->auditable_uid);
        $this->assertSame('User password reset.', $auditEntry->summary);
        $this->assertArrayNotHasKey('email', $auditEntry->metadata);
        $this->assertArrayNotHasKey('password', $auditEntry->metadata);
        $this->assertArrayNotHasKey('token', $auditEntry->metadata);
    }

    public function test_console_password_reset_rejects_unknown_email_without_trace_events(): void
    {
        $exitCode = Artisan::call('artifactflow:reset-password', [
            '--email' => 'missing@example.test',
            '--password' => 'new secure password',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('User does not exist.', Artisan::output());
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'user.password_reset')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'user.password_reset')->count());
    }

    public function test_console_password_reset_can_use_configured_password_fallback(): void
    {
        config(['app.reset_user_password' => 'configured reset password']);
        $user = $this->createUser('Configured Reset', 'configured-reset@example.test', 'old secure password');

        $exitCode = Artisan::call('artifactflow:reset-password', [
            '--email' => 'configured-reset@example.test',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertTrue(Hash::check('configured reset password', $user->refresh()->password));
        $this->assertStringNotContainsString('configured reset password', Artisan::output());
    }

    public function test_console_password_reset_reads_a_password_from_a_one_shot_secret_file(): void
    {
        $user = $this->createUser('File Reset', 'file-reset@example.test', 'old secure password');
        $secretFile = storage_path('framework/testing/reset-password-' . Str::random(12));
        file_put_contents($secretFile, "password from secret file\n");
        putenv('ARTIFACTFLOW_RESET_PASSWORD_FILE=' . $secretFile);

        try {
            $exitCode = Artisan::call('artifactflow:reset-password', [
                '--email' => 'file-reset@example.test',
            ]);
        } finally {
            putenv('ARTIFACTFLOW_RESET_PASSWORD_FILE');
            unlink($secretFile);
        }

        $this->assertSame(0, $exitCode);
        $this->assertTrue(Hash::check('password from secret file', $user->refresh()->password));
    }

    public function test_console_password_reset_rejects_short_password_without_changes(): void
    {
        $user = $this->createUser('Short Reset', 'short-reset@example.test', 'old secure password');
        $oldHash = $user->password;

        $exitCode = Artisan::call('artifactflow:reset-password', [
            '--email' => 'short-reset@example.test',
            '--password' => 'too-short',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('User password must be at least 12 characters.', Artisan::output());
        $this->assertSame($oldHash, $user->refresh()->password);
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'user.password_reset')->count());
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
