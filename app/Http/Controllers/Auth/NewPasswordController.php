<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Application\Identity\ResetUserPassword;
use App\Http\Requests\Auth\NewPasswordRequest;
use App\Models\User;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use LogicException;

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

    public function store(NewPasswordRequest $request, ResetUserPassword $resetUserPassword): RedirectResponse
    {
        $status = Password::broker()->reset(
            $request->credentials(),
            function (CanResetPasswordContract $user, string $password) use ($resetUserPassword): void {
                if (!$user instanceof User) {
                    throw new LogicException('Password reset broker returned an unsupported user model.');
                }

                $resetUserPassword->handle($user, $password);
            },
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
