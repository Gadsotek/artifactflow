<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Application\Identity\TurnstileConfiguration;
use App\Application\Identity\TurnstileVerifier;
use App\Http\Requests\AppFormRequest;
use Illuminate\Validation\ValidationException;

final class PasswordResetLinkRequest extends AppFormRequest
{
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
            'email' => ['required', 'string', 'email', 'max:255'],
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
     * @return array{email: string}
     */
    public function credentials(): array
    {
        return [
            'email' => $this->string('email')->toString(),
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
            TurnstileConfiguration::ACTION_PASSWORD_RESET_REQUEST,
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
