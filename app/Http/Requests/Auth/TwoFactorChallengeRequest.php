<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\AppFormRequest;

final class TwoFactorChallengeRequest extends AppFormRequest
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
            'code' => ['nullable', 'string', 'required_without:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'required_without:code'],
            'remember_device' => ['nullable', 'boolean'],
        ];
    }

    public function code(): string
    {
        return $this->string('code')->toString();
    }

    public function recoveryCode(): string
    {
        return $this->string('recovery_code')->toString();
    }

    public function rememberDevice(): bool
    {
        return (bool) $this->boolean('remember_device');
    }
}
