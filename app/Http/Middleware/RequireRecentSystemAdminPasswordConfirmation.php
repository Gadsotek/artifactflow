<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireRecentSystemAdminPasswordConfirmation
{
    public const string SESSION_KEY = 'auth.system_admin_password_confirmed_at';

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user instanceof User || !$user->is_system_admin) {
            abort(403);
        }

        if ($this->confirmationIsFresh($request)) {
            return $next($request);
        }

        return $this->redirectToConfirmation($request);
    }

    private function confirmationIsFresh(Request $request): bool
    {
        $confirmedAt = $request->session()->get(self::SESSION_KEY);

        if (!is_int($confirmedAt) && !(is_string($confirmedAt) && ctype_digit($confirmedAt))) {
            return false;
        }

        $confirmedTimestamp = (int) $confirmedAt;
        $currentTimestamp = now()->getTimestamp();

        if ($confirmedTimestamp > $currentTimestamp) {
            return false;
        }

        return ($currentTimestamp - $confirmedTimestamp) <= $this->timeoutSeconds();
    }

    private function timeoutSeconds(): int
    {
        $value = config('auth.admin_password_timeout', 900);
        $timeout = is_int($value) || is_string($value) ? (int) $value : 900;

        return max(60, $timeout);
    }

    private function redirectToConfirmation(Request $request): RedirectResponse
    {
        $request->session()->put(
            'url.intended',
            $request->isMethod('GET') ? $request->getRequestUri() : route('admin.users.index', absolute: false),
        );

        return redirect()->route('admin.password.confirm');
    }
}
