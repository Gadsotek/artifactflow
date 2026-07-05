<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Application\Identity\CreateSharedWorkspace;
use App\Domain\Identity\WorkspaceRole;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AuthenticationShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login_from_the_dashboard(): void
    {
        $this->get('/dashboard')
            ->assertRedirect('/login');
    }

    public function test_login_screen_is_available_but_registration_is_not_routed_by_default(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('artifactflow')
            ->assertSee('data-brand-mark', false)
            ->assertSee('href="/favicon.svg"', false)
            ->assertSee('Secure knowledge, beautifully organized.')
            ->assertSee('data-auth-shell', false)
            ->assertSee('Email')
            ->assertSee('Password')
            ->assertDontSee('Remember me')
            ->assertDontSee('name="remember"', false)
            ->assertDontSee('Register');

        $this->get('/register')->assertNotFound();
    }

    public function test_users_can_login_and_logout(): void
    {
        $user = $this->createUser('Login User', 'login@example.test', 'correct password');

        $this->post('/login', [
            'email' => 'login@example.test',
            'password' => 'correct password',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $event = DomainEvent::query()->where('event_type', 'user.logged_in')->sole();
        $this->assertSame($user->uid, $event->aggregate_uid);
        $this->assertSame($user->uid, $event->payload['user_uid']);
        $this->assertArrayNotHasKey('email', $event->payload);
        $this->assertArrayNotHasKey('password', $event->payload);

        $audit = AuditEntry::query()->where('action', 'user.logged_in')->sole();
        $this->assertSame($event->uid, $audit->event_uid);
        $this->assertSame($user->uid, $audit->actor_user_uid);
        $this->assertSame([], $audit->metadata);

        $this->post('/logout')->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_forged_stock_remember_me_input_is_ignored(): void
    {
        $this->createUser('Remember User', 'remember@example.test', 'correct password');

        $response = $this->post('/login', [
            'email' => 'remember@example.test',
            'password' => 'correct password',
            'remember' => '1',
        ])->assertRedirect('/dashboard');

        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertFalse(str_starts_with($cookie->getName(), 'remember_web_'));
        }
    }

    public function test_invalid_login_credentials_are_rejected(): void
    {
        $this->createUser('Login User', 'login@example.test', 'correct password');

        $this->post('/login', [
            'email' => 'login@example.test',
            'password' => 'wrong password',
        ])
            ->assertRedirect('/')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(0, DomainEvent::query()->where('event_type', 'user.logged_in')->count());
        $this->assertSame(0, AuditEntry::query()->where('action', 'user.logged_in')->count());
    }

    public function test_structured_login_identity_is_rejected_as_validation_input(): void
    {
        $this->post('/login', [
            'email' => ['login@example.test'],
            'password' => 'wrong password',
        ])
            ->assertRedirect('/')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_repeated_invalid_login_attempts_are_rate_limited_using_normalized_identity_and_source(): void
    {
        $user = $this->createUser('Rate Limited User', 'rate-limited@example.test', 'correct password');
        $server = ['REMOTE_ADDR' => '203.0.113.10'];

        foreach (range(1, 5) as $attempt) {
            $email = $attempt % 2 === 0
                ? ' RATE-LIMITED@example.test '
                : 'rate-limited@example.test';

            $this->withServerVariables($server)
                ->post('/login', [
                    'email' => $email,
                    'password' => 'wrong password',
                ])
                ->assertSessionHasErrors('email');
        }

        $this->withServerVariables($server)
            ->post('/login', [
                'email' => 'rate-limited@example.test',
                'password' => 'correct password',
            ])
            ->assertSessionHasErrors('email');

        $this->assertGuest();

        $this->travel(61)->seconds();

        $this->withServerVariables($server)
            ->post('/login', [
                'email' => ' RATE-LIMITED@example.test ',
                'password' => 'correct password',
            ])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_successful_login_clears_the_failed_attempt_budget(): void
    {
        $user = $this->createUser('Reset User', 'reset@example.test', 'correct password');
        $server = ['REMOTE_ADDR' => '203.0.113.11'];

        foreach (range(1, 4) as $attempt) {
            $this->withServerVariables($server)
                ->post('/login', [
                    'email' => $attempt % 2 === 0 ? 'RESET@example.test' : 'reset@example.test',
                    'password' => 'wrong password',
                ])
                ->assertSessionHasErrors('email');
        }

        $this->withServerVariables($server)
            ->post('/login', [
                'email' => ' reset@example.test ',
                'password' => 'correct password',
            ])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/');

        foreach (range(1, 4) as $attempt) {
            $this->withServerVariables($server)
                ->post('/login', [
                    'email' => 'reset@example.test',
                    'password' => 'wrong password',
                ])
                ->assertSessionHasErrors('email');
        }

        $this->withServerVariables($server)
            ->post('/login', [
                'email' => 'reset@example.test',
                'password' => 'correct password',
            ])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
    }

    public function test_dashboard_lists_the_authenticated_users_workspaces(): void
    {
        $user = $this->createUser('Workspace User', 'workspace@example.test');
        app(CreateSharedWorkspace::class)->handle($user, 'Platform Team');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-app-shell', false)
            ->assertSee('data-brand-mark', false)
            ->assertSee('data-primary-navigation', false)
            ->assertSee('Knowledge workspace')
            ->assertSee('Theme')
            ->assertSee('Workspace User')
            ->assertSee('Platform Team')
            ->assertSee('Workspace User')
            ->assertSessionHas('current_workspace_uid');
    }

    public function test_user_can_switch_to_a_workspace_they_belong_to(): void
    {
        $user = $this->createUser('Workspace User', 'workspace@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($user, 'Platform Team');

        $this->actingAs($user)
            ->post("/workspaces/{$workspace->uid}/switch")
            ->assertRedirect('/dashboard')
            ->assertSessionHas('current_workspace_uid', $workspace->uid);
    }

    public function test_user_cannot_switch_to_an_unrelated_workspace(): void
    {
        $user = $this->createUser('Workspace User', 'workspace@example.test');
        $otherUser = $this->createUser('Other User', 'other@example.test');
        $otherWorkspace = app(CreateSharedWorkspace::class)->handle($otherUser, 'Other Team');

        $this->actingAs($user)
            ->post("/workspaces/{$otherWorkspace->uid}/switch")
            ->assertForbidden();
    }

    public function test_dashboard_uses_the_existing_current_workspace_when_it_is_allowed(): void
    {
        $user = $this->createUser('Workspace User', 'workspace@example.test');
        $workspace = app(CreateSharedWorkspace::class)->handle($user, 'Platform Team');

        WorkspaceMembership::query()->where('workspace_uid', $workspace->uid)
            ->where('user_uid', $user->uid)
            ->update(['role' => WorkspaceRole::Editor]);

        $this->actingAs($user)
            ->withSession(['current_workspace_uid' => $workspace->uid])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Platform Team')
            ->assertSessionHas('current_workspace_uid', $workspace->uid);
    }

    private function createUser(string $name, string $email, string $password = 'password'): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);
    }
}
