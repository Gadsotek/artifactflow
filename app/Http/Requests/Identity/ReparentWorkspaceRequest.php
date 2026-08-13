<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Http\Requests\AppFormRequest;

final class ReparentWorkspaceRequest extends AppFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'parent_workspace_uid' => ['nullable', 'string', 'ulid'],
            'confirmed' => ['required', 'accepted'],
            'preview_id' => ['required', 'string', 'ulid'],
        ];
    }

    public function previewId(): string
    {
        return $this->string('preview_id')->toString();
    }

    public function parentWorkspaceUid(): ?string
    {
        $workspaceUid = trim($this->string('parent_workspace_uid')->toString());

        return $workspaceUid === '' ? null : $workspaceUid;
    }
}
