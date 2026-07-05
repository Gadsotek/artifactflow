<?php

declare(strict_types=1);

use App\Application\Mcp\McpEffectiveAuthority;
use App\Application\Mcp\McpRequestContext;
use App\Application\PageCatalog\PageAccess;
use App\Http\Middleware\RefreshScopedServicesForHttpRequest;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the FrankenPHP-worker lifecycle contract: the request-scoped
 * authorization services must be forgotten at the start of each request so a
 * reused worker container never bleeds one actor's effective authority into the
 * next request.
 */
it('forgets request-scoped authorization services when the flush middleware runs', function (): void {
    foreach ([PageAccess::class, McpRequestContext::class, McpEffectiveAuthority::class] as $service) {
        $first = app($service);

        expect(app($service))->toBe($first);

        (new RefreshScopedServicesForHttpRequest())->handle(
            Request::create('/pages'),
            static fn (): Response => new Response(),
        );

        expect(app($service))->not->toBe($first);
    }
});

it('keeps the scoped-service flush middleware prepended to the global stack', function (): void {
    $middleware = app(\Illuminate\Contracts\Http\Kernel::class)->getGlobalMiddleware();

    expect($middleware)->toContain(RefreshScopedServicesForHttpRequest::class)
        ->and($middleware[0])->toBe(RefreshScopedServicesForHttpRequest::class);
});
