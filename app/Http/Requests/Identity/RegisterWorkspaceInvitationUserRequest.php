<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Http\Requests\AppFormRequest;

final class RegisterWorkspaceInvitationUserRequest extends AppFormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ];
    }

    public function name(): string
    {
        return $this->string('name')->toString();
    }

    public function password(): string
    {
        return $this->string('password')->toString();
    }
}
