<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Identity\VerifyTwoFactorCode;
use App\Http\Middleware\RequireRecentSystemAdminTwoFactorConfirmation;
use App\Http\Requests\Identity\ConfirmTwoFactorSecurityActionRequest;
use App\Http\Support\SafeIntendedRedirect;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final readonly class SystemAdminTwoFactorConfirmationController
{
    public function __construct(
        private VerifyTwoFactorCode $verifyTwoFactorCode,
        private SafeIntendedRedirect $safeIntendedRedirect,
    ) {
    }

    public function create(Request $request): View|RedirectResponse
    {
        $user = $this->systemAdmin($request);

        if (!$user->hasEnabledTwoFactor()) {
            return $this->redirectToEnrollment();
        }

        return view('admin.confirm-two-factor');
    }

    /**
     * @throws ValidationException
     */
    public function store(ConfirmTwoFactorSecurityActionRequest $request): RedirectResponse
    {
        $user = $this->systemAdmin($request);

        if (!$user->hasEnabledTwoFactor()) {
            return $this->redirectToEnrollment();
        }

        $candidate = $request->candidate();
        $verified = $candidate !== '' && (
            $this->verifyTwoFactorCode->verifyTotpAndAdvance($user, $candidate, $user->auth_revision)
            || $this->verifyTwoFactorCode->consumeRecoveryCode($user, $candidate, $user->auth_revision)
        );

        if (!$verified) {
            throw ValidationException::withMessages([
                'code' => 'Enter a valid authenticator or recovery code to confirm admin access.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put(
            RequireRecentSystemAdminTwoFactorConfirmation::SESSION_KEY,
            now()->getTimestamp(),
        );
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

    private function redirectToEnrollment(): RedirectResponse
    {
        return redirect()
            ->route('settings.two-factor.index')
            ->with('status', 'Enable two-factor authentication before entering Administration.');
    }
}
