<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Http\Requests\AppFormRequest;

final class PreviewReparentWorkspaceRequest extends AppFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'parent_workspace_uid' => ['nullable', 'string', 'ulid'],
        ];
    }

    public function parentWorkspaceUid(): ?string
    {
        $workspaceUid = trim($this->string('parent_workspace_uid')->toString());

        return $workspaceUid === '' ? null : $workspaceUid;
    }
}
