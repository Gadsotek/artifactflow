<?php

declare(strict_types=1);

namespace App\Http\Requests\PageCatalog;

use App\Http\Requests\AppFormRequest;

final class StoreCategoryRequest extends AppFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
        ];
    }

    public function categoryName(): string
    {
        return $this->string('name')->toString();
    }
}
