<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Http\Requests\AppFormRequest;
use App\Rules\StorableText;
use Illuminate\Validation\Rules\Password;

final class StoreManagedUserRequest extends AppFormRequest
{
    public function authorize(): bool
    {
        return $this->authenticatedUserIsSystemAdmin();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', new StorableText(), 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)],
        ];
    }
}
