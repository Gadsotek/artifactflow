<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Application\Identity\CreateSharedWorkspace;
use App\Domain\Identity\WorkspaceRole;
use App\Http\Support\AuthenticationSessionRevision;
use App\Models\AuditEntry;
use App\Models\DomainEvent;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AuthenticationShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_guests_to_login(): void
    {
        $this->get('/')
            ->assertRedirect('/login');
    }

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
            ->assertDontSee('Public registration is disabled.')
            ->assertDontSee('Authentication is protected by rate limiting.')
            ->assertDontSee('Register');

        $this->get('/register')->assertNotFound();
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function sessionCookiePolicies(): iterable
    {
        yield 'local HTTP' => [false, 'artifactflow_session'];
        yield 'secure host cookie' => [true, '__Host-artifactflow_session'];
    }

    #[DataProvider('sessionCookiePolicies')]
    public function test_users_can_login_and_logout(bool $secure, string $cookieName): void
    {
        config([
            'session.cookie' => $cookieName,
            'session.secure' => $secure,
            'session.path' => '/',
            'session.domain' => null,
        ]);
        $user = $this->createUser('Login User', 'login@example.test', 'correct password');

        $response = $this->post('/login', [
            'email' => 'login@example.test',
            'password' => 'correct password',
        ])->assertRedirect('/dashboard');

        $cookie = $response->getCookie($cookieName, decrypt: false);
        $this->assertNotNull($cookie);
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertSame($secure, $cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
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

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function sessionCookieReplayPolicies(): iterable
    {
        yield 'active credential' => ['__Host-artifactflow_session', '__Host-artifactflow_session', true];
        yield 'legacy credential' => ['artifactflow_session', 'artifactflow_session', false];
        yield 'legacy ciphertext renamed' => ['artifactflow_session', '__Host-artifactflow_session', false];
    }

    #[DataProvider('sessionCookieReplayPolicies')]
    public function test_secure_sessions_accept_only_credentials_bound_to_the_active_cookie_name(
        string $issuedName,
        string $submittedName,
        bool $accepted,
    ): void {
        config([
            'session.driver' => 'database',
            'session.encrypt' => true,
            'session.cookie' => '__Host-artifactflow_session',
            'session.secure' => true,
            'session.path' => '/',
            'session.domain' => null,
        ]);
        $user = $this->createUser('Cookie Replay User', 'cookie-replay@example.test');
        $guard = Auth::guard('web');
        $this->assertInstanceOf(SessionGuard::class, $guard);
        $store = app(SessionManager::class)->driver();
        $this->assertInstanceOf(Store::class, $store);
        $this->assertSame('__Host-artifactflow_session', $store->getName());
        $store->put($guard->getName(), $user->uid);
        $store->put(AuthenticationSessionRevision::SESSION_KEY, $user->auth_revision);
        $sessionId = $store->getId();
        $store->save();
        $this->assertDatabaseHas('sessions', ['id' => $sessionId]);

        // Preserve the persisted session, but remove in-process authentication
        // so only the incoming browser cookie can authenticate this request.
        $store->flush();
        Auth::forgetGuards();
        $encrypter = app(Encrypter::class);
        $ciphertext = $encrypter->encrypt(
            CookieValuePrefix::create($issuedName, $encrypter->getKey()) . $sessionId,
            false,
        );
        $response = $this->withUnencryptedCookie($submittedName, $ciphertext)->get('/dashboard');

        if ($accepted) {
            $response->assertOk();
            $this->assertAuthenticatedAs($user);
        } else {
            $response->assertRedirect('/login');
            $this->assertGuest();
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
