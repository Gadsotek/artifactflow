<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Http\Requests\AppFormRequest;

final class ConfirmTwoFactorSecurityActionRequest extends AppFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ];
    }

    public function candidate(): string
    {
        $candidate = trim($this->string('code')->toString());

        return $candidate !== ''
            ? $candidate
            : trim($this->string('recovery_code')->toString());
    }
}
