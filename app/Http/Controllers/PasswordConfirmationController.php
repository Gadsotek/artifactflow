<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Http\Requests\Auth\ConfirmPasswordRequest;
use App\Http\Support\SafeIntendedRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final readonly class PasswordConfirmationController
{
    use Concerns\ResolvesAuthenticatedUser;

    public function __construct(
        private SafeIntendedRedirect $safeIntendedRedirect,
    ) {
    }

    public function create(Request $request): View
    {
        $this->authenticatedUser($request);

        return view('auth.confirm-password');
    }

    /**
     * @throws ValidationException
     */
    public function store(ConfirmPasswordRequest $request): RedirectResponse
    {
        $user = $this->authenticatedUser($request);

        if (!Hash::check($request->password(), $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'The provided password is incorrect.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put(RequireRecentPasswordConfirmation::SESSION_KEY, now()->getTimestamp());
        $request->session()->regenerateToken();
        $this->safeIntendedRedirect->forgetUnsafeIntendedUrl($request);

        return redirect()
            ->intended(route('settings.two-factor.index', absolute: false))
            ->with('status', 'Password confirmed.');
    }
}
