<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Identity\ConfirmTwoFactorEnrollment;
use App\Application\Identity\DisableTwoFactor;
use App\Application\Identity\PasswordConfirmationFreshness;
use App\Application\Identity\RegenerateTwoFactorRecoveryCodes;
use App\Application\Identity\StartTwoFactorEnrollment;
use App\Application\Identity\TrustedDeviceManager;
use App\Application\Identity\TwoFactorEnrollmentFreshness;
use App\Application\Identity\TwoFactorQrCode;
use App\Application\Identity\VerifyTwoFactorCode;
use App\Http\Middleware\RequireRecentPasswordConfirmation;
use App\Http\Middleware\RequireRecentSystemAdminTwoFactorConfirmation;
use App\Http\Requests\Identity\ConfirmTwoFactorEnrollmentRequest;
use App\Http\Requests\Identity\ConfirmTwoFactorSecurityActionRequest;
use App\Http\Support\AuthenticationSessionRevision;
use App\Models\TrustedDevice;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

final readonly class TwoFactorSettingsController
{
    use Concerns\ResolvesAuthenticatedUser;

    public function __construct(
        private VerifyTwoFactorCode $verifyTwoFactorCode,
        private PasswordConfirmationFreshness $passwordConfirmationFreshness,
        private TwoFactorEnrollmentFreshness $enrollmentFreshness,
        private AuthenticationSessionRevision $sessionRevision,
    ) {
    }

    public function index(Request $request, TwoFactorQrCode $qrCode): Response|RedirectResponse
    {
        $user = $this->authenticatedUser($request);
        $recoveryCodes = $request->session()->pull('two_factor_recovery_codes', []);
        $pendingSecret = $this->pendingSecret($user);
        $passwordConfirmedAt = $request->session()->get(RequireRecentPasswordConfirmation::SESSION_KEY);
        $enrollmentPasswordIsFresh = $this->passwordConfirmationFreshness
            ->isFreshForTwoFactorEnrollment($passwordConfirmedAt);

        if ($pendingSecret !== null && !$enrollmentPasswordIsFresh) {
            return redirect()
                ->route('settings.password.confirm')
                ->with('status', 'The enrollment window expired. Confirm your password, then start again for a new QR code.');
        }

        $enrollmentMustRestart = $pendingSecret !== null && !$this->enrollmentFreshness->isCurrent(
            $user->two_factor_secret_created_at,
            $passwordConfirmedAt,
        );
        if ($enrollmentMustRestart) {
            $pendingSecret = null;
        }
        $enrollmentPasswordExpiresAt = $user->hasEnabledTwoFactor()
            ? null
            : $this->passwordConfirmationFreshness->expiresAtForTwoFactorEnrollment(
                $request->session()->get(RequireRecentPasswordConfirmation::SESSION_KEY),
            );
        $enrollmentPasswordSecondsRemaining = $enrollmentPasswordExpiresAt === null
            ? null
            : max(0, $enrollmentPasswordExpiresAt - now()->getTimestamp());

        return response()->view(
            'settings.two-factor.index',
            [
                'user' => $user,
                'pendingSecret' => $pendingSecret,
                'enrollmentMustRestart' => $enrollmentMustRestart,
                'enrollmentPasswordExpiresAt' => $enrollmentPasswordExpiresAt,
                'enrollmentPasswordRemaining' => $enrollmentPasswordSecondsRemaining === null
                    ? null
                    : sprintf(
                        '%d:%02d',
                        intdiv($enrollmentPasswordSecondsRemaining, 60),
                        $enrollmentPasswordSecondsRemaining % 60,
                    ),
                'qrCodeDataUri' => $pendingSecret === null ? null : $qrCode->dataUri($user->email, $pendingSecret),
                'recoveryCodes' => is_array($recoveryCodes) ? array_values(array_filter(
                    $recoveryCodes,
                    static fn (mixed $code): bool => is_string($code),
                )) : [],
                'trustedDevices' => $user->trustedDevices()
                    ->orderByDesc('last_used_at')
                    ->orderByDesc('created_at')
                    ->get(),
            ],
            headers: ['Cache-Control' => 'no-store, private'],
        );
    }

    public function enroll(Request $request, StartTwoFactorEnrollment $startEnrollment): RedirectResponse
    {
        $user = $this->authenticatedUser($request);
        $startEnrollment->handle($user, $user->auth_revision);

        return redirect()
            ->route('settings.two-factor.index')
            ->with('status', 'Scan the QR code and confirm a code to enable two-factor authentication.');
    }

    public function confirm(
        ConfirmTwoFactorEnrollmentRequest $request,
        ConfirmTwoFactorEnrollment $confirmEnrollment,
    ): RedirectResponse {
        $user = $this->authenticatedUser($request);
        $confirmation = $confirmEnrollment->handle(
            $user,
            $request->code(),
            $request->session()->get(RequireRecentPasswordConfirmation::SESSION_KEY),
            $user->auth_revision,
        );
        $request->session()->regenerate();
        Auth::guard('web')->setUser($confirmation->user);
        $this->sessionRevision->bindRevision($request, $confirmation->authRevision);
        if ($confirmation->user->is_system_admin) {
            $request->session()->put(
                RequireRecentSystemAdminTwoFactorConfirmation::SESSION_KEY,
                now()->getTimestamp(),
            );
        }
        $request->session()->regenerateToken();

        return redirect()
            ->route('settings.two-factor.index')
            ->with('status', 'Two-factor authentication enabled.')
            ->with('two_factor_recovery_codes', $confirmation->recoveryCodes);
    }

    public function disable(
        ConfirmTwoFactorSecurityActionRequest $request,
        DisableTwoFactor $disableTwoFactor,
    ): RedirectResponse {
        $user = $this->authenticatedUser($request);
        $expectedAuthRevision = $user->auth_revision;
        $this->confirmSecondFactor($request, $user, $expectedAuthRevision);
        $disabledUser = $disableTwoFactor->handle($user, $expectedAuthRevision);
        $request->session()->regenerate();
        Auth::guard('web')->setUser($disabledUser);
        $this->sessionRevision->bindRevision($request, $disabledUser->auth_revision);
        $request->session()->forget(RequireRecentSystemAdminTwoFactorConfirmation::SESSION_KEY);
        $request->session()->regenerateToken();

        return redirect()
            ->route('settings.two-factor.index')
            ->with('status', 'Two-factor authentication disabled.');
    }

    public function regenerateRecoveryCodes(
        ConfirmTwoFactorSecurityActionRequest $request,
        RegenerateTwoFactorRecoveryCodes $regenerateRecoveryCodes,
    ): RedirectResponse {
        $user = $this->authenticatedUser($request);
        $expectedAuthRevision = $user->auth_revision;
        $this->confirmSecondFactor($request, $user, $expectedAuthRevision);
        $rotation = $regenerateRecoveryCodes->handle($user, $expectedAuthRevision);
        $request->session()->regenerate();
        Auth::guard('web')->setUser($rotation->user);
        $this->sessionRevision->bindRevision($request, $rotation->authRevision);
        $request->session()->regenerateToken();

        return redirect()
            ->route('settings.two-factor.index')
            ->with('status', 'Two-factor recovery codes regenerated.')
            ->with('two_factor_recovery_codes', $rotation->recoveryCodes);
    }

    /**
     * Turning off two-factor authentication or rotating the recovery codes is a
     * security-degrading action, so a recent password confirmation (enforced by
     * middleware) is not sufficient on its own: an attacker who only holds the
     * password could otherwise strip the second factor. Require live possession
     * of the second factor — a current authenticator code or an unused recovery
     * code — exactly as the login challenge does.
     *
     * @throws ValidationException
     */
    private function confirmSecondFactor(
        ConfirmTwoFactorSecurityActionRequest $request,
        User $user,
        int $expectedAuthRevision,
    ): void {
        if (!$user->hasEnabledTwoFactor()) {
            return;
        }

        // Accept the second factor from either the combined code field or an
        // explicit recovery-code field. Try the TOTP path first (it self-rejects
        // anything that is not a six-digit code, so a recovery code falls through
        // without consuming one) before attempting a single-use recovery code.
        $candidate = $request->candidate();

        $verified = $candidate !== '' && (
            $this->verifyTwoFactorCode->verifyTotpAndAdvance($user, $candidate, $expectedAuthRevision)
            || $this->verifyTwoFactorCode->consumeRecoveryCode($user, $candidate, $expectedAuthRevision)
        );

        if (!$verified) {
            throw ValidationException::withMessages([
                'code' => 'Enter a valid authenticator or recovery code to confirm this change.',
            ]);
        }
    }

    public function revokeTrustedDevice(
        Request $request,
        TrustedDevice $trustedDevice,
        TrustedDeviceManager $trustedDevices,
    ): RedirectResponse {
        $trustedDevices->revoke($this->authenticatedUser($request), $trustedDevice);

        return redirect()
            ->route('settings.two-factor.index')
            ->with('status', 'Trusted device revoked.');
    }

    public function revokeAllTrustedDevices(
        Request $request,
        TrustedDeviceManager $trustedDevices,
    ): RedirectResponse {
        $user = $this->authenticatedUser($request);
        $revocation = $trustedDevices->revokeAll($user, $user->auth_revision);
        $request->session()->regenerate();
        Auth::guard('web')->setUser($revocation->user);
        $this->sessionRevision->bindRevision($request, $revocation->authRevision);
        $request->session()->regenerateToken();

        return redirect()
            ->route('settings.two-factor.index')
            ->with('status', 'Trusted devices revoked.');
    }

    private function pendingSecret(User $user): ?string
    {
        if ($user->hasEnabledTwoFactor()) {
            return null;
        }

        $rawSecret = $user->getRawOriginal('two_factor_secret');
        if (!is_string($rawSecret) || $rawSecret === '') {
            return null;
        }

        try {
            $secret = Crypt::decryptString($rawSecret);
        } catch (DecryptException) {
            return null;
        }

        return $secret !== '' ? $secret : null;
    }
}
