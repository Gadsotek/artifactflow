<?php

declare(strict_types=1);

namespace App\Http\Requests\PageCatalog;

use App\Application\PageCatalog\PageAccess;
use App\Http\Requests\AppFormRequest;
use App\Models\Page;
use App\Models\User;

final class HardDeletePageRequest extends AppFormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $page = $this->route('page');

        if (!$user instanceof User || !$page instanceof Page) {
            return false;
        }

        return app(PageAccess::class)->canHardDelete($user, $page);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', 'max:255'],
        ];
    }
}
