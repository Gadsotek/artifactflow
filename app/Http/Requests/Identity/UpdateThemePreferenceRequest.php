<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Domain\Identity\ThemePreference;
use App\Http\Requests\AppFormRequest;
use Illuminate\Validation\Rule;

final class UpdateThemePreferenceRequest extends AppFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'theme' => ['required', Rule::enum(ThemePreference::class)],
        ];
    }

    public function theme(): ThemePreference
    {
        return ThemePreference::from($this->string('theme')->toString());
    }
}
