<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Tests\Support\RecordingLogger;
use Tests\TestCase;

final class TurnstileLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_turnstile_is_absent_by_default_and_enabled_by_configuring_both_keys(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('challenges.cloudflare.com', false)
            ->assertDontSee('cf-turnstile', false);

        $this->enableTurnstile();

        $response = $this->get('/login')
            ->assertOk()
            ->assertSee('https://challenges.cloudflare.com/turnstile/v0/api.js', false)
            ->assertSee('class="cf-turnstile"', false)
            ->assertSee('data-sitekey="site-key"', false)
            ->assertSee('data-action="login"', false)
            ->assertSee('data-size="flexible"', false)
            ->assertDontSee('private-secret', false);

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $content = (string) $response->getContent();
        $scriptMatched = preg_match(
            '/<script nonce="(?<nonce>[^"]+)" src="https:\/\/challenges\.cloudflare\.com\/turnstile\/v0\/api\.js" async defer><\/script>/',
            $content,
            $scriptMatches,
        );
        $cspMatched = preg_match("/'nonce-(?<nonce>[^']+)'/", $csp, $cspMatches);

        $this->assertSame(1, $scriptMatched);
        $this->assertSame(1, $cspMatched);
        $this->assertSame($cspMatches['nonce'], $scriptMatches['nonce']);
        $this->assertMatchesRegularExpression(
            "/script-src [^;]*'nonce-[^']+'[^;]*https:\\/\\/challenges\\.cloudflare\\.com/",
            $csp,
        );
        $this->assertStringContainsString(
            'frame-src http://127.0.0.1:18081 https://challenges.cloudflare.com',
            $csp,
        );

        $otherPageCsp = (string) $this->get('/up')
            ->assertOk()
            ->headers
            ->get('Content-Security-Policy');
        $this->assertStringNotContainsString('challenges.cloudflare.com', $otherPageCsp);
    }

    public function test_partial_configuration_returns_clear_service_unavailable_guidance(): void
    {
        foreach ([
            ['turnstile.site_key' => 'site-key', 'turnstile.secret_key' => null],
            ['turnstile.site_key' => null, 'turnstile.secret_key' => 'private-secret'],
        ] as $configuration) {
            config($configuration);

            foreach ([
                '/login',
                '/forgot-password',
                '/reset-password/test-token?email=user%40example.test',
            ] as $path) {
                $this->get($path)
                    ->assertStatus(503)
                    ->assertSee('Authentication challenge is not configured correctly.')
                    ->assertSee(
                        'Set both TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY, or remove both to disable Turnstile.',
                    )
                    ->assertDontSee('private-secret');
            }
        }
    }

    public function test_invalid_complete_configuration_returns_service_unavailable_guidance(): void
    {
        foreach ([
            ['turnstile.expected_hostname' => 'https://app.example.test'],
            ['turnstile.connect_timeout_seconds' => 0],
            ['turnstile.timeout_seconds' => ['invalid']],
        ] as $configuration) {
            $this->enableTurnstile();
            config($configuration);

            foreach ([
                '/login',
                '/forgot-password',
                '/reset-password/test-token?email=user%40example.test',
            ] as $path) {
                $this->get($path)
                    ->assertStatus(503)
                    ->assertSee('Authentication challenge is not configured correctly.')
                    ->assertSee('Verify the expected hostname and timeout settings.')
                    ->assertDontSee('private-secret');
            }
        }
    }

    public function test_valid_turnstile_token_is_verified_before_login(): void
    {
        $this->enableTurnstile();
        $user = $this->createUser();
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => true,
                'hostname' => 'app.example.test',
                'action' => 'login',
            ]),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->post('/login', [
                'email' => 'login@example.test',
                'password' => 'correct password',
                'cf-turnstile-response' => 'valid-widget-token',
            ])
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        Http::assertSentCount(1);
        Http::assertSent(static function (Request $request): bool {
            $data = $request->data();

            return $request->url() === 'https://challenges.cloudflare.com/turnstile/v0/siteverify'
                && $request->method() === 'POST'
                && ($data['secret'] ?? null) === 'private-secret'
                && ($data['response'] ?? null) === 'valid-widget-token'
                && ($data['remoteip'] ?? null) === '203.0.113.50';
        });
    }

    public function test_missing_structured_and_oversized_turnstile_tokens_are_rejected_without_network_io(): void
    {
        $this->enableTurnstile();
        $this->createUser();
        Http::fake();

        foreach ([
            null,
            ['forged-token'],
            str_repeat('x', 2049),
        ] as $token) {
            $payload = [
                'email' => 'login@example.test',
                'password' => 'correct password',
            ];

            if ($token !== null) {
                $payload['cf-turnstile-response'] = $token;
            }

            $this->post('/login', $payload)
                ->assertSessionHasErrors('cf-turnstile-response');

            $this->assertGuest();
        }

        Http::assertNothingSent();
    }

    public function test_turnstile_failures_mismatched_claims_and_transport_errors_fail_closed(): void
    {
        $this->enableTurnstile();
        $this->createUser();

        $responses = [
            Http::response(['success' => false, 'error-codes' => ['invalid-input-response']]),
            Http::response([
                'success' => true,
                'hostname' => 'app.example.test',
                'action' => 'password-reset',
            ]),
            Http::response([
                'success' => true,
                'hostname' => 'other.example.test',
                'action' => 'login',
            ]),
            Http::response('not-json'),
            Http::response(['success' => true], 503),
        ];

        foreach ($responses as $index => $response) {
            Http::fake([
                'https://challenges.cloudflare.com/turnstile/v0/siteverify' => $response,
            ]);

            $email = $index === 0
                ? 'login@example.test'
                : 'failure-' . $index . '@example.test';

            $this->postInvalidChallenge($email)
                ->assertSessionHasErrors([
                    'cf-turnstile-response' => 'Verification failed. Please try again.',
                ]);
            $this->assertGuest();
        }

        Http::fake(static function (): never {
            throw new ConnectionException('private transport detail');
        });

        $this->postInvalidChallenge('transport@example.test')
            ->assertSessionHasErrors([
                'cf-turnstile-response' => 'Verification failed. Please try again.',
            ])
            ->assertDontSee('private transport detail');
        $this->assertGuest();
    }

    public function test_siteverify_failures_log_only_bounded_operator_diagnostics(): void
    {
        $this->enableTurnstile();
        $this->createUser();
        $logger = new RecordingLogger();
        Log::swap($logger);
        $responses = [
            Http::response([
                'success' => false,
                'error-codes' => [
                    'invalid-input-response',
                    "forged\ncode",
                    str_repeat('x', 65),
                    ['structured'],
                ],
            ]),
            Http::response([
                'success' => true,
                'hostname' => 'unexpected.example.test',
                'action' => 'login',
            ]),
        ];
        Http::fake(static function () use (&$responses) {
            $response = array_shift($responses);

            return $response ?? Http::response(status: 500);
        });

        $this->postInvalidChallenge('login@example.test')
            ->assertSessionHasErrors('cf-turnstile-response');
        $this->postInvalidChallenge('hostname-mismatch@example.test')
            ->assertSessionHasErrors('cf-turnstile-response');

        $records = collect($logger->records)
            ->where('level', 'warning')
            ->where('message', 'turnstile.siteverify_failed')
            ->values();

        $this->assertCount(2, $records);
        $this->assertSame('rejected', $records[0]['context']['reason'] ?? null);
        $this->assertSame(
            ['invalid-input-response'],
            $records[0]['context']['error_codes'] ?? null,
        );
        $this->assertSame('hostname_mismatch', $records[1]['context']['reason'] ?? null);
        foreach ($records as $record) {
            $encoded = json_encode($record['context'], JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('private-secret', $encoded);
            $this->assertStringNotContainsString('valid-widget-token', $encoded);
            $this->assertArrayNotHasKey('hostname', $record['context']);
            $this->assertArrayNotHasKey('remote_ip', $record['context']);
        }
    }

    public function test_failed_turnstile_verifications_are_bounded_by_the_source_ip_login_limit(): void
    {
        $this->enableTurnstile();
        config([
            'rate_limits.login_ip_per_minute' => 2,
            'rate_limits.login_account_per_hour' => 20,
        ]);
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/v0/siteverify' => Http::response([
                'success' => false,
            ]),
        ]);
        $server = ['REMOTE_ADDR' => '203.0.113.60'];

        foreach (['first@example.test', 'second@example.test'] as $email) {
            $this->withServerVariables($server)
                ->post('/login', [
                    'email' => $email,
                    'password' => 'wrong password',
                    'cf-turnstile-response' => 'invalid-widget-token',
                ])
                ->assertSessionHasErrors('cf-turnstile-response');
        }

        $this->withServerVariables($server)
            ->post('/login', [
                'email' => 'third@example.test',
                'password' => 'wrong password',
                'cf-turnstile-response' => 'invalid-widget-token',
            ])
            ->assertTooManyRequests();

        Http::assertSentCount(2);
    }

    public function test_failed_challenge_does_not_consume_the_account_password_guess_budget(): void
    {
        $this->enableTurnstile();
        $this->createUser();
        config(['rate_limits.login_account_per_hour' => 1]);
        $challengeCount = 0;
        Http::fake(static function () use (&$challengeCount) {
            ++$challengeCount;

            return Http::response($challengeCount === 1 ? [
                'success' => false,
            ] : [
                'success' => true,
                'hostname' => 'app.example.test',
                'action' => 'login',
            ]);
        });

        $this->postInvalidChallenge('login@example.test')
            ->assertSessionHasErrors('cf-turnstile-response');

        $this->post('/login', [
            'email' => 'login@example.test',
            'password' => 'wrong password',
            'cf-turnstile-response' => 'valid-widget-token',
        ])
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        $this->assertGuest();
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

    private function createUser(): User
    {
        return User::query()->create([
            'name' => 'Login User',
            'email' => 'login@example.test',
            'password' => Hash::make('correct password'),
        ]);
    }

    /**
     * @return TestResponse<SymfonyResponse>
     */
    private function postInvalidChallenge(string $email): TestResponse
    {
        return $this->post('/login', [
            'email' => $email,
            'password' => 'correct password',
            'cf-turnstile-response' => 'invalid-widget-token',
        ]);
    }
}
