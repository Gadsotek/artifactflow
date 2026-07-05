<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Identity\RecordSuccessfulLogin;
use App\Application\Identity\TrustedDeviceManager;
use App\Application\Identity\TwoFactorPendingChallenge;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Support\SafeIntendedRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class AuthenticatedSessionController
{
    public function __construct(
        private readonly SafeIntendedRedirect $safeIntendedRedirect,
        private readonly TwoFactorPendingChallenge $pendingChallenge,
        private readonly TrustedDeviceManager $trustedDevices,
    ) {
    }

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, RecordSuccessfulLogin $recordSuccessfulLogin): RedirectResponse
    {
        $user = $request->validateCredentials();

        if ($user->hasEnabledTwoFactor()) {
            $trustedDevice = $this->trustedDevices->findValidDevice($user, $request);
            if ($trustedDevice === null) {
                $this->pendingChallenge->create($request, $user, $request->remember());

                return redirect()->route('login.two-factor');
            }
        }

        Auth::login($user, $request->remember());
        $recordSuccessfulLogin->handle($user);
        $request->session()->regenerate();
        $this->safeIntendedRedirect->forgetUnsafeIntendedUrl($request);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
