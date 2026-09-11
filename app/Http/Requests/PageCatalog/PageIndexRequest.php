<?php

declare(strict_types=1);

namespace App\Http\Requests\PageCatalog;

use App\Http\Requests\AppFormRequest;

final class PageIndexRequest extends AppFormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'min:1', 'max:10000']];
    }
}
