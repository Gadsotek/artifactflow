<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Http\Requests\AppFormRequest;

final class RemoveWorkspaceMemberRequest extends AppFormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'replacement_owner_user_uid' => ['nullable', 'string', 'ulid'],
        ];
    }

    public function replacementOwnerUserUid(): ?string
    {
        $uid = trim($this->string('replacement_owner_user_uid')->toString());

        return $uid === '' ? null : $uid;
    }
}
