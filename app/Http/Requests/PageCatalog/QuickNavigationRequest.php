<?php

declare(strict_types=1);

namespace App\Http\Requests\PageCatalog;

use App\Http\Requests\AppFormRequest;

final class QuickNavigationRequest extends AppFormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:200'],
            'workspace_uid' => ['nullable', 'string', 'regex:/^(all|[0-9A-Za-z]{26})$/'],
        ];
    }
}
