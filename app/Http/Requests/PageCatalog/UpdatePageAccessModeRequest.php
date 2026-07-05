<?php

declare(strict_types=1);

namespace App\Http\Requests\PageCatalog;

use App\Domain\PageCatalog\PageAccessMode;
use App\Http\Requests\AppFormRequest;
use Illuminate\Validation\Rule;

final class UpdatePageAccessModeRequest extends AppFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'access_mode' => ['required', Rule::enum(PageAccessMode::class)],
        ];
    }

    public function accessMode(): PageAccessMode
    {
        return PageAccessMode::from($this->string('access_mode')->toString());
    }
}
