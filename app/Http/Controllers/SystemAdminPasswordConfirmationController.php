<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Middleware\RequireRecentSystemAdminPasswordConfirmation;
use App\Http\Requests\Auth\ConfirmPasswordRequest;
use App\Http\Support\SafeIntendedRedirect;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class SystemAdminPasswordConfirmationController
{
    public function __construct(
        private readonly SafeIntendedRedirect $safeIntendedRedirect,
    ) {
    }

    public function create(Request $request): View
    {
        $this->systemAdmin($request);

        return view('admin.confirm-password');
    }

    /**
     * @throws ValidationException
     */
    public function store(ConfirmPasswordRequest $request): RedirectResponse
    {
        $user = $this->systemAdmin($request);

        if (!Hash::check($request->password(), $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'The provided password is incorrect.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put(RequireRecentSystemAdminPasswordConfirmation::SESSION_KEY, now()->getTimestamp());
        $request->session()->regenerateToken();
        $this->safeIntendedRedirect->forgetUnsafeIntendedUrl($request);

        return redirect()
            ->intended(route('admin.users.index', absolute: false))
            ->with('status', 'Admin access confirmed.');
    }

    private function systemAdmin(Request $request): User
    {
        $user = $request->user();

        if (!$user instanceof User || !$user->is_system_admin) {
            abort(403);
        }

        return $user;
    }
}
