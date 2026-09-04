<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

final class TurnstilePasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_turnstile_is_absent_by_default_and_appears_on_both_password_recovery_forms_when_enabled(): void
    {
        $this->get('/forgot-password')
            ->assertOk()
            ->assertDontSee('challenges.cloudflare.com', false)
            ->assertDontSee('cf-turnstile', false);
        $this->get('/reset-password/test-token?email=user%40example.test')
            ->assertOk()
            ->assertDontSee('challenges.cloudflare.com', false)
            ->assertDontSee('cf-turnstile', false);

        $this->enableTurnstile();

        $forgotResponse = $this->get('/forgot-password')
            ->assertOk()
            ->assertSee('https://challenges.cloudflare.com/turnstile/v0/api.js', false)
            ->assertSee('class="cf-turnstile"', false)
            ->assertSee('data-sitekey="site-key"', false)
            ->assertSee('data-action="password_reset_request"', false)
            ->assertDontSee('private-secret', false);
        $this->assertTurnstileAllowedByCsp(
            (string) $forgotResponse->headers->get('Content-Security-Policy'),
        );

        $resetResponse = $this->get('/reset-password/test-token?email=user%40example.test')
            ->assertOk()
            ->assertSee('https://challenges.cloudflare.com/turnstile/v0/api.js', false)
            ->assertSee('class="cf-turnstile"', false)
            ->assertSee('data-sitekey="site-key"', false)
            ->assertSee('data-action="password_reset"', false)
            ->assertDontSee('private-secret', false);
        $this->assertTurnstileAllowedByCsp(
            (string) $resetResponse->headers->get('Content-Security-Policy'),
        );
    }

    public function test_password_reset_link_request_requires_a_valid_action_bound_challenge(): void
    {
        $this->enableTurnstile();
        Notification::fake();
        $user = $this->createUser('reset-link@example.test');
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => true,
                'hostname' => 'app.example.test',
                'action' => 'password_reset_request',
            ]),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.70'])
            ->post('/forgot-password', [
                'email' => $user->email,
                'cf-turnstile-response' => 'valid-reset-link-token',
            ])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas(
                'status',
                'If the address exists, a password reset link has been sent.',
            );

        Notification::assertSentTo($user, ResetPasswordNotification::class);
        Http::assertSent(static function (Request $request): bool {
            $data = $request->data();

            return ($data['response'] ?? null) === 'valid-reset-link-token'
                && ($data['remoteip'] ?? null) === '203.0.113.70';
        });
    }

    public function test_new_password_submission_requires_a_valid_action_bound_challenge(): void
    {
        $this->enableTurnstile();
        $user = $this->createUser('reset-password@example.test');
        $token = $this->passwordBroker()->createToken($user);
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => true,
                'hostname' => 'app.example.test',
                'action' => 'password_reset',
            ]),
        ]);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new secure password',
            'password_confirmation' => 'new secure password',
            'cf-turnstile-response' => 'valid-password-reset-token',
        ])
            ->assertRedirect('/login')
            ->assertSessionHas(
                'status',
                'Your password has been reset. You can sign in with the new password.',
            );

        $this->assertTrue(Hash::check('new secure password', $user->refresh()->password));
    }

    public function test_password_recovery_rejects_missing_or_wrong_action_challenges_without_side_effects(): void
    {
        $this->enableTurnstile();
        Notification::fake();
        $user = $this->createUser('rejected-reset@example.test');
        $token = $this->passwordBroker()->createToken($user);
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => true,
                'hostname' => 'app.example.test',
                'action' => 'login',
            ]),
        ]);

        $this->post('/forgot-password', [
            'email' => $user->email,
        ])->assertSessionHasErrors('cf-turnstile-response');
        Http::assertNothingSent();
        Notification::assertNothingSent();

        $this->post('/forgot-password', [
            'email' => $user->email,
            'cf-turnstile-response' => 'wrong-action-token',
        ])->assertSessionHasErrors([
            'cf-turnstile-response' => 'Verification failed. Please try again.',
        ]);
        Notification::assertNothingSent();

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new secure password',
            'password_confirmation' => 'new secure password',
            'cf-turnstile-response' => 'wrong-action-token',
        ])->assertSessionHasErrors([
            'cf-turnstile-response' => 'Verification failed. Please try again.',
        ]);

        $this->assertTrue(Hash::check('old secure password', $user->refresh()->password));
        $this->assertTrue($this->passwordBroker()->tokenExists($user, $token));
    }

    private function enableTurnstile(): void
    {
        config([
            'app.runtime_role' => 'app',
            'turnstile.site_key' => 'site-key',
            'turnstile.secret_key' => 'private-secret',
            'turnstile.expected_hostname' => 'app.example.test',
            'turnstile.connect_timeout_seconds' => 2,
            'turnstile.timeout_seconds' => 5,
        ]);
    }

    private function passwordBroker(): PasswordBroker
    {
        $broker = Password::broker();
        $this->assertInstanceOf(PasswordBroker::class, $broker);

        return $broker;
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'name' => 'Password Reset User',
            'email' => $email,
            'password' => Hash::make('old secure password'),
        ]);
    }

    private function assertTurnstileAllowedByCsp(string $csp): void
    {
        $this->assertMatchesRegularExpression(
            "/script-src [^;]*'nonce-[^']+'[^;]*https:\\/\\/challenges\\.cloudflare\\.com/",
            $csp,
        );
        $this->assertStringContainsString(
            'frame-src http://127.0.0.1:18081 https://challenges.cloudflare.com',
            $csp,
        );
    }
}
