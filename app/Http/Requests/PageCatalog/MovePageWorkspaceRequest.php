<?php

declare(strict_types=1);

namespace App\Http\Requests\PageCatalog;

use App\Http\Requests\AppFormRequest;

final class MovePageWorkspaceRequest extends AppFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'target_workspace_uid' => ['required', 'ulid'],
            'target_owner_user_uid' => ['required', 'ulid'],
            'confirm_move' => ['accepted'],
        ];
    }

    public function targetWorkspaceUid(): string
    {
        return $this->string('target_workspace_uid')->toString();
    }

    public function targetOwnerUserUid(): string
    {
        return $this->string('target_owner_user_uid')->toString();
    }
}
