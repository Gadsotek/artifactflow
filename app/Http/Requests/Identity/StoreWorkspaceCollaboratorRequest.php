<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Domain\Identity\WorkspaceRole;
use App\Http\Requests\AppFormRequest;
use Illuminate\Validation\Rule;

final class StoreWorkspaceCollaboratorRequest extends AppFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'user_uid' => ['required', 'string', 'max:64'],
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
        ];
    }
}
