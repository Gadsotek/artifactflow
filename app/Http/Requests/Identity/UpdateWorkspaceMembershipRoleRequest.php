<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Domain\Identity\WorkspaceRole;
use App\Http\Requests\AppFormRequest;
use Illuminate\Validation\Rule;

final class UpdateWorkspaceMembershipRoleRequest extends AppFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
        ];
    }

    public function workspaceRole(): WorkspaceRole
    {
        return WorkspaceRole::from($this->string('role')->toString());
    }
}
