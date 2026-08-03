<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keep Laravel's synthetic OPTIONS responses inside the runtime-role boundary.
 *
 * This replaces Laravel's global HandleCors middleware at the same position in
 * the stack. That placement applies the existing role/host decision after
 * trusted proxy normalization but before a configured CORS preflight can return
 * early. The artifact origin publishes no CORS surface, so even an otherwise
 * valid artifact path fails closed instead of exposing a middleware-less
 * response there.
 */
final class EnforceOptionsRuntimeRoleSurface
{
    public function __construct(
        private readonly EnforceRuntimeRoleSurface $runtimeRoleSurface,
        private readonly HandleCors $cors,
    ) {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->isMethod('OPTIONS')) {
            return $this->cors->handle($request, $next);
        }

        return $this->runtimeRoleSurface->handle(
            $request,
            function (Request $request) use ($next): Response {
                if (config('app.runtime_role') === 'artifact-host') {
                    abort(404);
                }

                return $this->cors->handle($request, $next);
            },
        );
    }
}
