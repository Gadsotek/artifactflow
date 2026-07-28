<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Application\Identity\TurnstileConfiguration;
use App\Application\Identity\TurnstileVerifier;
use App\Http\Requests\AppFormRequest;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

final class NewPasswordRequest extends AppFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(TurnstileConfiguration $turnstile): array
    {
        $rules = [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
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
     * @return array{token: string, email: string, password: string, password_confirmation: string}
     */
    public function credentials(): array
    {
        return [
            'token' => $this->string('token')->toString(),
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
            'password_confirmation' => $this->string('password_confirmation')->toString(),
        ];
    }

    /**
     * @throws ValidationException
     */
    public function ensureTurnstileIsValid(TurnstileVerifier $turnstile): void
    {
        if (!$turnstile->enabled() || $turnstile->verify(
            $this->string('cf-turnstile-response')->toString(),
            $this->ip(),
            TurnstileConfiguration::ACTION_PASSWORD_RESET,
        )) {
            return;
        }

        throw ValidationException::withMessages([
            'cf-turnstile-response' => 'Verification failed. Please try again.',
        ]);
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
}
