<?php

declare(strict_types=1);

namespace App\Http\Requests\PageCatalog;

use App\Http\Requests\AppFormRequest;
use Illuminate\Validation\Rule;

final class UpdatePagePresenceRequest extends AppFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'state' => ['required', Rule::in(['editing', 'idle'])],
        ];
    }

    public function isEditing(): bool
    {
        return $this->string('state')->toString() === 'editing';
    }
}
