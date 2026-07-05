<?php

declare(strict_types=1);

namespace App\Http\Requests\PageCatalog;

use App\Domain\Identity\WorkspaceRole;
use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Http\Requests\AppFormRequest;
use Illuminate\Validation\Rule;

final class StorePageAccessGrantRequest extends AppFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'subject_type' => ['required', Rule::enum(PageAccessSubjectType::class)],
            'user_email' => [
                'nullable',
                'required_if:subject_type,' . PageAccessSubjectType::User->value,
                'email:rfc',
                'max:255',
            ],
            'workspace_uid' => [
                'nullable',
                'required_if:subject_type,' . PageAccessSubjectType::Workspace->value,
                'ulid',
            ],
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
        ];
    }

    public function subjectType(): PageAccessSubjectType
    {
        return PageAccessSubjectType::from($this->string('subject_type')->toString());
    }

    public function workspaceRole(): WorkspaceRole
    {
        return WorkspaceRole::from($this->string('role')->toString());
    }

    public function userEmail(): ?string
    {
        return $this->nullableString('user_email');
    }

    public function workspaceUid(): ?string
    {
        return $this->nullableString('workspace_uid');
    }

    private function nullableString(string $field): ?string
    {
        $value = trim($this->string($field)->toString());

        return $value === '' ? null : $value;
    }
}
