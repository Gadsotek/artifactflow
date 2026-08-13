<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Http\Requests\AppFormRequest;
use App\Rules\StorableText;
use Illuminate\Validation\Rule;

final class StoreWorkspaceRequest extends AppFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', new StorableText(), 'max:120'],
            'parent_workspace_uid' => ['nullable', 'string', 'ulid'],
            'inherits_parent_memberships' => ['sometimes', 'boolean'],
            'return_to' => ['nullable', Rule::in(['library'])],
        ];
    }

    public function workspaceName(): string
    {
        return $this->string('name')->toString();
    }

    public function returnsToLibrary(): bool
    {
        return $this->string('return_to')->toString() === 'library';
    }

    public function parentWorkspaceUid(): ?string
    {
        $workspaceUid = trim($this->string('parent_workspace_uid')->toString());

        return $workspaceUid === '' ? null : $workspaceUid;
    }

    public function inheritsParentMemberships(): bool
    {
        return !$this->exists('inherits_parent_memberships')
            || $this->boolean('inherits_parent_memberships');
    }
}
