<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Identity\TurnstileConfiguration;
use App\Application\Identity\TurnstileVerifier;
use App\Http\Requests\Auth\PasswordResetLinkRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

final class PasswordResetLinkController
{
    public const string STATUS = 'If the address exists, a password reset link has been sent.';

    public function __construct(
        private readonly TurnstileConfiguration $turnstileConfiguration,
        private readonly TurnstileVerifier $turnstileVerifier,
    ) {
    }

    public function create(): View
    {
        return view('auth.forgot-password', [
            'turnstileSiteKey' => $this->turnstileConfiguration->publicSiteKey(),
            'turnstileScriptUrl' => $this->turnstileConfiguration->enabled()
                ? TurnstileConfiguration::SCRIPT_URL
                : null,
        ]);
    }

    public function store(PasswordResetLinkRequest $request): RedirectResponse
    {
        $request->ensureTurnstileIsValid($this->turnstileVerifier);
        Password::broker()->sendResetLink($request->credentials());

        return redirect()
            ->route('password.request')
            ->with('status', self::STATUS);
    }
}
