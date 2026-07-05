<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\AppFormRequest;

final class PasswordResetLinkRequest extends AppFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
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
