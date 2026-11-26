<?php

declare(strict_types=1);

namespace App\Http\Requests\Administration;

use App\Http\Requests\AppFormRequest;

final class UpdatePassageRetrievalRequest extends AppFormRequest
{
    public function authorize(): bool
    {
        return $this->authenticatedUserIsSystemAdmin();
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'generation' => ['required', 'integer', 'min:0'],
        ];
    }
}
