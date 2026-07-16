<?php

declare(strict_types=1);

namespace App\Http\Requests\PageCatalog;

use App\Application\Administration\InstallationLimitSettings;
use App\Http\Requests\AppFormRequest;

final class StoreArtifactDraftPreviewCapabilityRequest extends AppFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'workspace_uid' => ['required', 'string', 'ulid'],
            'content_bytes' => [
                'required',
                'integer',
                'min:1',
                'max:' . app(InstallationLimitSettings::class)->integer('pages.max_html_bytes'),
            ],
            'content_sha256' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
        ];
    }

    public function workspaceUid(): string
    {
        return $this->string('workspace_uid')->toString();
    }

    public function contentBytes(): int
    {
        return $this->integer('content_bytes');
    }

    public function contentSha256(): string
    {
        return $this->string('content_sha256')->toString();
    }
}
