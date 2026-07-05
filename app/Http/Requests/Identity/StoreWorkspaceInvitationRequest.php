<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Domain\Identity\WorkspaceRole;
use App\Http\Requests\AppFormRequest;
use Illuminate\Validation\Rule;

final class StoreWorkspaceInvitationRequest extends AppFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'return_to' => ['nullable', Rule::in(['library'])],
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
        ];
    }
}
