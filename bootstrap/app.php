<?php

declare(strict_types=1);

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\EnforceMcpOrigin;
use App\Http\Middleware\EnforceRuntimeRoleSurface;
use App\Http\Middleware\EnforceTwoFactorEnrollment;
use App\Http\Middleware\RefreshScopedServicesForHttpRequest;
use App\Http\Middleware\RejectArtifactHostRuntime;
use App\Http\Middleware\RequireCompletedInstallation;
use App\Http\Middleware\ThrottleMcpRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
    )
    ->withBroadcasting(__DIR__ . '/../routes/channels.php', [
        'middleware' => [
            'web',
            RejectArtifactHostRuntime::class,
            'auth',
            EnforceTwoFactorEnrollment::class,
            'throttle:artifactflow-authenticated',
        ],
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Must run first on every request: forgets scoped authorization caches
        // so FrankenPHP worker reuse can't bleed one actor's authority into the
        // next request. See RefreshScopedServicesForHttpRequest.
        $middleware->prepend(RefreshScopedServicesForHttpRequest::class);
        $middleware->trustHosts(
            at: static function (): array {
                $hosts = [];

                foreach ([config('app.url'), config('app.artifact_url')] as $url) {
                    if (!is_string($url)) {
                        continue;
                    }

                    $host = parse_url($url, PHP_URL_HOST);

                    if (is_string($host) && $host !== '') {
                        $hosts[] = '^' . preg_quote($host, '#') . '$';
                    }
                }

                return array_values(array_unique($hosts));
            },
            subdomains: false,
        );
        // The IP-trust boundary: no explicit at: here on purpose. This closure runs
        // during bootstrap, before the config repository is bound, so we cannot read
        // config('trustedproxy.proxies') yet. Laravel's TrustProxies reads that key
        // lazily at request time instead (config/trustedproxy.php parses the
        // TRUSTED_PROXIES env into a list), and ProductionSecurityConfiguration
        // ::ensureTrustedProxies() fails the boot gate on an empty or wildcard list
        // in production. Do not add `at: config(...)` here — it breaks bootstrap.
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
        // Role/host enforcement must remain first so the artifact origin never
        // exposes app setup state. Installation readiness then runs before
        // Laravel's database-backed StartSession middleware.
        $middleware->prependToGroup('web', RequireCompletedInstallation::class);
        $middleware->prependToGroup('web', EnforceRuntimeRoleSurface::class);
        $middleware->appendToGroup('web', AddSecurityHeaders::class);
        $middleware->prependToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            ThrottleMcpRequests::class,
        );
        // The MCP Origin check is a transport-level gate: it must run before
        // authentication so a cross-origin browser request is refused (403) without
        // a token lookup, ahead of auth:mcp. Prepended after ThrottleMcpRequests so
        // IP flood protection still applies to rejected cross-origin requests.
        $middleware->prependToPriorityList(
            \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            EnforceMcpOrigin::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['code', 'recovery_code']);
        $exceptions->respond(static function (Response $response, Throwable $exception, Request $request): Response {
            return app(AddSecurityHeaders::class)->apply($request, $response);
        });
    })
    ->create();
