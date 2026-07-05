<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Administration\InstallationLimitSettings;
use App\Models\User;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnforceTwoFactorEnrollment
{
    public function __construct(
        private InstallationLimitSettings $settings,
    ) {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user instanceof User || $user->hasEnabledTwoFactor() || !$this->requiresTwoFactor($user)) {
            return $next($request);
        }

        if ($this->isAllowedRoute($request)) {
            return $next($request);
        }

        if ($request->is('broadcasting/auth')) {
            abort(403);
        }

        return $this->redirectToEnrollment();
    }

    private function requiresTwoFactor(User $user): bool
    {
        $settings = $this->settings->current();

        return $user->two_factor_required
            || $settings->twoFactorRequiredForAllUsers
            || ($user->is_system_admin && $settings->twoFactorRequiredForSystemAdmins);
    }

    private function isAllowedRoute(Request $request): bool
    {
        return $request->routeIs(
            'settings.two-factor.*',
            'settings.password.*',
            'admin.password.*',
            'logout',
            'settings.theme',
        );
    }

    private function redirectToEnrollment(): RedirectResponse
    {
        return redirect()
            ->route('settings.two-factor.index')
            ->with('status', 'Two-factor authentication is required before continuing.');
    }
}
