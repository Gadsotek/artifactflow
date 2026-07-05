<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AuthRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_service_registration_is_not_exposed_but_password_recovery_is_available(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/forgot-password')->assertOk();
    }

    public function test_login_is_rate_limited_by_source_ip_and_account_identity(): void
    {
        config([
            'rate_limits.login_ip_per_minute' => 2,
            'rate_limits.login_account_per_hour' => 2,
        ]);
        User::query()->create([
            'name' => 'Login User',
            'email' => 'login@example.test',
            'password' => Hash::make('correct horse battery staple'),
        ]);

        $this->post('/login', [
            'email' => 'first@example.test',
            'password' => 'wrong password',
        ])->assertSessionHasErrors('email');

        $this->post('/login', [
            'email' => 'second@example.test',
            'password' => 'wrong password',
        ])->assertSessionHasErrors('email');

        $this->post('/login', [
            'email' => 'third@example.test',
            'password' => 'wrong password',
        ])->assertTooManyRequests();

        foreach (['203.0.113.10', '203.0.113.11'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->post('/login', [
                    'email' => 'login@example.test',
                    'password' => 'wrong password',
                ])
                ->assertSessionHasErrors('email');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.12'])
            ->post('/login', [
                'email' => 'login@example.test',
                'password' => 'wrong password',
            ])
            ->assertTooManyRequests();
    }

    public function test_login_discards_external_intended_redirects(): void
    {
        User::query()->create([
            'name' => 'Login User',
            'email' => 'login@example.test',
            'password' => Hash::make('correct horse battery staple'),
        ]);

        $this->withSession(['url.intended' => 'https://evil.example/phish'])
            ->post('/login', [
                'email' => 'login@example.test',
                'password' => 'correct horse battery staple',
            ])
            ->assertRedirect('/dashboard');
    }

    public function test_successful_login_clears_per_email_rate_limit_bucket(): void
    {
        config([
            'rate_limits.login_ip_per_minute' => 10,
            'rate_limits.login_account_per_hour' => 2,
        ]);
        User::query()->create([
            'name' => 'Target User',
            'email' => 'target@example.test',
            'password' => Hash::make('correct horse battery staple'),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->post('/login', [
                'email' => 'target@example.test',
                'password' => 'wrong password',
            ])
            ->assertSessionHasErrors('email');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.21'])
            ->post('/login', [
                'email' => ' TARGET@example.test ',
                'password' => 'correct horse battery staple',
            ])
            ->assertRedirect('/dashboard');

        $this->post('/logout')->assertRedirect('/');

        foreach (['203.0.113.22', '203.0.113.23'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->post('/login', [
                    'email' => 'target@example.test',
                    'password' => 'wrong password',
                ])
                ->assertSessionHasErrors('email');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.24'])
            ->post('/login', [
                'email' => 'target@example.test',
                'password' => 'wrong password',
            ])
            ->assertTooManyRequests();
    }

    public function test_successful_logins_do_not_consume_the_source_ip_failure_budget(): void
    {
        config([
            'rate_limits.login_ip_per_minute' => 2,
            'rate_limits.login_account_per_hour' => 10,
        ]);

        foreach (range(1, 3) as $number) {
            User::query()->create([
                'name' => 'Shared IP User ' . $number,
                'email' => 'shared-ip-' . $number . '@example.test',
                'password' => Hash::make('correct horse battery staple'),
            ]);

            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.90'])
                ->post('/login', [
                    'email' => 'shared-ip-' . $number . '@example.test',
                    'password' => 'correct horse battery staple',
                ])
                ->assertRedirect('/dashboard');

            $this->post('/logout')->assertRedirect('/');
        }

        foreach (['first', 'second'] as $prefix) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.90'])
                ->post('/login', [
                    'email' => $prefix . '-missing@example.test',
                    'password' => 'wrong password',
                ])
                ->assertSessionHasErrors('email');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.90'])
            ->post('/login', [
                'email' => 'third-missing@example.test',
                'password' => 'wrong password',
            ])
            ->assertTooManyRequests();
    }

    public function test_account_failure_bucket_does_not_block_a_correct_password(): void
    {
        config([
            'rate_limits.login_ip_per_minute' => 10,
            'rate_limits.login_account_per_hour' => 2,
        ]);
        User::query()->create([
            'name' => 'Target User',
            'email' => 'lockout-target@example.test',
            'password' => Hash::make('correct horse battery staple'),
        ]);

        foreach (['203.0.113.31', '203.0.113.32'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->post('/login', [
                    'email' => 'lockout-target@example.test',
                    'password' => 'wrong password',
                ])
                ->assertSessionHasErrors('email');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.33'])
            ->post('/login', [
                'email' => 'lockout-target@example.test',
                'password' => 'wrong password',
            ])
            ->assertTooManyRequests();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.34'])
            ->post('/login', [
                'email' => 'lockout-target@example.test',
                'password' => 'correct horse battery staple',
            ])
            ->assertRedirect('/dashboard');
    }
}
