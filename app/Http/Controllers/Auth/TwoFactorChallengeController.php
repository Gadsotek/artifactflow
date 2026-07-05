<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Identity\RecordSuccessfulLogin;
use App\Application\Identity\TrustedDeviceManager;
use App\Application\Identity\TwoFactorPendingChallenge;
use App\Application\Identity\VerifyTwoFactorCode;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Http\Support\SafeIntendedRedirect;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final readonly class TwoFactorChallengeController
{
    public function __construct(
        private TwoFactorPendingChallenge $pendingChallenge,
        private VerifyTwoFactorCode $verifyTwoFactorCode,
        private TrustedDeviceManager $trustedDevices,
        private SafeIntendedRedirect $safeIntendedRedirect,
    ) {
    }

    public function create(Request $request): View|RedirectResponse
    {
        if (!$this->pendingChallenge->user($request) instanceof User) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Complete sign in before entering a two-factor code.']);
        }

        return view('auth.two-factor-challenge', [
            'trustedDeviceDays' => $this->trustedDeviceDays(),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(
        TwoFactorChallengeRequest $request,
        RecordSuccessfulLogin $recordSuccessfulLogin,
    ): RedirectResponse {
        $user = $this->pendingChallenge->user($request);
        if (!$user instanceof User) {
            $this->pendingChallenge->recordFailure($request);
            $this->throwGenericFailure();
        }

        $usedTotp = false;
        $verified = false;

        if ($request->code() !== '') {
            $usedTotp = true;
            $verified = $this->verifyTwoFactorCode->verifyTotpAndAdvance($user, $request->code());
        } elseif ($request->recoveryCode() !== '') {
            $verified = $this->verifyTwoFactorCode->consumeRecoveryCode($user, $request->recoveryCode());
        }

        if (!$verified) {
            $this->pendingChallenge->recordFailure($request);
            $this->throwGenericFailure();
        }

        $remember = $this->pendingChallenge->remember($request);
        $rememberDevice = $usedTotp && $request->rememberDevice();
        $this->pendingChallenge->clear($request);

        Auth::login($user, $remember);
        $recordSuccessfulLogin->handle($user);
        $request->session()->regenerate();
        $this->safeIntendedRedirect->forgetUnsafeIntendedUrl($request);

        $redirect = redirect()->intended(route('dashboard', absolute: false));
        if ($rememberDevice) {
            $trustedDevice = $this->trustedDevices->remember($user, $request);
            $redirect->withCookie(cookie(
                name: TrustedDeviceManager::COOKIE_NAME,
                value: $trustedDevice['token'],
                minutes: $this->trustedDeviceMinutes(),
                path: null,
                domain: null,
                secure: (bool) config('session.secure', false),
                httpOnly: true,
                raw: false,
                sameSite: $this->trustedDeviceSameSite(),
            ));
        }

        return $redirect;
    }

    /**
     * @throws ValidationException
     */
    private function throwGenericFailure(): never
    {
        throw ValidationException::withMessages([
            'code' => 'The provided two-factor code is invalid.',
        ]);
    }

    private function trustedDeviceMinutes(): int
    {
        return $this->trustedDeviceDays() * 24 * 60;
    }

    private function trustedDeviceDays(): int
    {
        $value = config('auth.two_factor_trusted_device_days', 30);
        $days = is_int($value) || is_string($value) ? (int) $value : 30;

        return max(1, $days);
    }

    private function trustedDeviceSameSite(): string
    {
        $sameSite = config('session.same_site', 'lax');

        return is_string($sameSite) && in_array(strtolower($sameSite), ['lax', 'strict'], true)
            ? strtolower($sameSite)
            : 'lax';
    }
}
