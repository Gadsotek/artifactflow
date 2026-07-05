<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Http\Requests\AppFormRequest;

final class ConfirmTwoFactorEnrollmentRequest extends AppFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string'],
        ];
    }

    public function code(): string
    {
        return $this->string('code')->toString();
    }
}
