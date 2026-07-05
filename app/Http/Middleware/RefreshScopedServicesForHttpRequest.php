<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class RefreshScopedServicesForHttpRequest
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        app()->forgetScopedInstances();

        if ($request->is('mcp')) {
            Auth::forgetGuards();
        }

        return $next($request);
    }
}
