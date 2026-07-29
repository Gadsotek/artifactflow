<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Application\Identity\TurnstileConfiguration;
use App\Application\Identity\TurnstileVerifier;
use App\Http\Requests\AppFormRequest;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class LoginRequest extends AppFormRequest
{
    private const int MAX_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 60;

    private const int ACCOUNT_DECAY_SECONDS = 3600;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(TurnstileConfiguration $turnstile): array
    {
        $rules = [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];

        if ($turnstile->enabled()) {
            $rules['cf-turnstile-response'] = [
                'required',
                'string',
                'max:' . TurnstileConfiguration::MAX_TOKEN_LENGTH,
            ];
        }

        return $rules;
    }

    /**
     * @throws ValidationException
     */
    public function validateCredentials(TurnstileVerifier $turnstile): User
    {
        $this->ensureIsNotRateLimited();

        if ($turnstile->enabled() && !$turnstile->verify(
            $this->string('cf-turnstile-response')->toString(),
            $this->ip(),
            TurnstileConfiguration::ACTION_LOGIN,
        )) {
            $this->failTurnstile();
        }

        $credentials = [
            'email' => $this->normalizedEmail(),
            'password' => $this->string('password')->toString(),
        ];
        $this->runDummyPasswordCheckWhenAccountIsUnknown(
            email: $credentials['email'],
            password: $credentials['password'],
        );

        $provider = Auth::getProvider();
        $user = $provider->retrieveByCredentials($credentials);

        if (!$user instanceof User || !$provider->validateCredentials($user, $credentials)) {
            $this->failAuthentication();
        }

        $this->clearRateLimiters();

        return $user;
    }

    public function remember(): bool
    {
        return false;
    }

    /**
     * @throws ValidationException
     */
    private function failAuthentication(): never
    {
        if (RateLimiter::tooManyAttempts($this->accountThrottleKey(), $this->accountMaxAttempts())) {
            abort(429);
        }

        RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);
        RateLimiter::hit($this->sourceIpThrottleKey(), self::DECAY_SECONDS);
        RateLimiter::hit($this->accountThrottleKey(), self::ACCOUNT_DECAY_SECONDS);

        throw ValidationException::withMessages([
            'email' => __('auth.failed'),
        ]);
    }

    /**
     * A failed challenge did not test an account password, so it consumes the
     * source-IP and email/IP budgets without poisoning the account-global
     * credential budget that protects users from distributed password guessing.
     *
     * @throws ValidationException
     */
    private function failTurnstile(): never
    {
        RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);
        RateLimiter::hit($this->sourceIpThrottleKey(), self::DECAY_SECONDS);

        throw ValidationException::withMessages([
            'cf-turnstile-response' => 'Verification failed. Please try again.',
        ]);
    }

    private function clearRateLimiters(): void
    {
        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->accountThrottleKey());
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (!is_string($email)) {
            return;
        }

        $this->merge([
            'email' => strtolower(trim($email)),
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(): void
    {
        if (RateLimiter::tooManyAttempts($this->sourceIpThrottleKey(), $this->sourceIpMaxAttempts())) {
            abort(429);
        }

        $key = $this->throttleKey();

        if (!RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return;
        }

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'email' => (string) __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }

    private function normalizedEmail(): string
    {
        return strtolower(trim($this->string('email')->toString()));
    }

    private function throttleKey(): string
    {
        $sourceIp = $this->ip() ?? 'unknown';

        return 'login:' . hash('sha256', $this->normalizedEmail() . '|' . $sourceIp);
    }

    private function accountThrottleKey(): string
    {
        return 'login-account:' . hash('sha256', $this->normalizedEmail());
    }

    private function sourceIpThrottleKey(): string
    {
        return 'login-ip:' . ($this->ip() ?? 'unknown');
    }

    private function accountMaxAttempts(): int
    {
        $value = config('rate_limits.login_account_per_hour', 20);
        $limit = is_int($value) || is_string($value) ? (int) $value : 20;

        return max(1, $limit);
    }

    private function sourceIpMaxAttempts(): int
    {
        $value = config('rate_limits.login_ip_per_minute', 20);
        $limit = is_int($value) || is_string($value) ? (int) $value : 20;

        return max(1, $limit);
    }

    private function runDummyPasswordCheckWhenAccountIsUnknown(string $email, string $password): void
    {
        $exists = User::query()
            ->where('email', $email)
            ->exists();

        if ($exists) {
            return;
        }

        $dummyHash = config('auth.dummy_password_hash');

        if (!is_string($dummyHash) || $dummyHash === '') {
            return;
        }

        Hash::check($password, $dummyHash);
    }
}
