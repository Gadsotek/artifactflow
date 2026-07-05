<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Http\Requests\AppFormRequest;
use App\Rules\StorableText;

final class UpdateWorkspaceSettingsRequest extends AppFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', new StorableText(), 'max:160'],
            'allow_editor_invites' => ['sometimes', 'boolean'],
            'allow_editor_page_sharing' => ['sometimes', 'boolean'],
        ];
    }
}
