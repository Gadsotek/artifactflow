<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Identity\ResetPasswordWithToken;
use App\Http\Requests\Auth\NewPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

final class NewPasswordController
{
    private const string RESET_FAILED_MESSAGE = 'Password reset link is invalid or has expired.';

    public function create(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'email' => $request->query('email', ''),
            'token' => $token,
        ]);
    }

    public function store(NewPasswordRequest $request, ResetPasswordWithToken $resetPassword): RedirectResponse
    {
        $credentials = $request->credentials();
        $status = $resetPassword->handle(
            email: $credentials['email'],
            token: $credentials['token'],
            newPassword: $credentials['password'],
        );

        if ($status === Password::PASSWORD_RESET) {
            $request->session()->regenerate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->with('status', 'Your password has been reset. You can sign in with the new password.');
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => self::RESET_FAILED_MESSAGE]);
    }
}
