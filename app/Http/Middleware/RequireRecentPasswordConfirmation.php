<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Identity\PasswordConfirmationFreshness;
use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RequireRecentPasswordConfirmation
{
    public const string SESSION_KEY = 'auth.password_confirmed_at';

    public function __construct(
        private readonly PasswordConfirmationFreshness $freshness,
    ) {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user instanceof User) {
            abort(403);
        }

        if ($this->confirmationIsFresh($request, $user)) {
            return $next($request);
        }

        return $this->redirectToConfirmation($request);
    }

    private function confirmationIsFresh(Request $request, User $user): bool
    {
        $confirmedAt = $request->session()->get(self::SESSION_KEY);

        if (
            !$user->hasEnabledTwoFactor()
            && $request->routeIs('settings.two-factor.enroll', 'settings.two-factor.confirm')
        ) {
            return $this->freshness->isFreshForTwoFactorEnrollment($confirmedAt);
        }

        return $this->freshness->isFresh($confirmedAt);
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
}
