<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\AppFormRequest;

final class ConfirmPasswordRequest extends AppFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
        ];
    }

    public function password(): string
    {
        return $this->string('password')->toString();
    }
}
