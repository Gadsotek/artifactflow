<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\AppFormRequest;
use Illuminate\Validation\Rules\Password as PasswordRule;

final class NewPasswordRequest extends AppFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::min(12)],
        ];
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
