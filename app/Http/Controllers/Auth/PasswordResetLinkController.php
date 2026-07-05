<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\PasswordResetLinkRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

final class PasswordResetLinkController
{
    public const string STATUS = 'If the address exists, a password reset link has been sent.';

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(PasswordResetLinkRequest $request): RedirectResponse
    {
        Password::broker()->sendResetLink($request->credentials());

        return redirect()
            ->route('password.request')
            ->with('status', self::STATUS);
    }
}
