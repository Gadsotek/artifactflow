<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Identity\UpdateThemePreference;
use App\Http\Requests\Identity\UpdateThemePreferenceRequest;
use Illuminate\Http\RedirectResponse;

final readonly class ThemePreferenceController
{
    use Concerns\ResolvesAuthenticatedUser;

    public function __construct(
        private UpdateThemePreference $updateThemePreference,
    ) {
    }

    public function __invoke(UpdateThemePreferenceRequest $request): RedirectResponse
    {
        $user = $this->authenticatedUser($request);

        $this->updateThemePreference->handle($user, $request->theme()->value);

        return redirect()->route('dashboard');
    }
}
