<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireRecentPasswordConfirmation
{
    public const string SESSION_KEY = 'auth.password_confirmed_at';

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user() instanceof User) {
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

    private function redirectToConfirmation(Request $request): RedirectResponse
    {
        $request->session()->put(
            'url.intended',
            $request->isMethod('GET') ? $this->safeRelativePath($request) : route('settings.two-factor.index'),
        );

        return redirect()->route('settings.password.confirm');
    }

    private function safeRelativePath(Request $request): string
    {
        $path = $request->getRequestUri();

        if (
            preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_starts_with($path, '/\\')
        ) {
            return route('settings.two-factor.index', absolute: false);
        }

        return $path;
    }

    private function timeoutSeconds(): int
    {
        $value = config('auth.password_timeout', 900);
        $timeout = is_int($value) || is_string($value) ? (int) $value : 900;

        return max(60, $timeout);
    }
}
