<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Infrastructure\Security\OriginNormalizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streamable-HTTP transport hardening for the MCP endpoint: reject a browser
 * request that carries a foreign Origin — the MCP specification's DNS-rebinding
 * defense. The browser sets Origin and page script cannot forge it, so a foreign
 * Origin is a call from a page this server does not serve and is refused with 403.
 *
 * An ABSENT Origin is allowed: non-browser MCP clients (CLI agents) send none, and
 * the endpoint is bearer-authenticated with no ambient cookies, so there is nothing
 * for a rebinding attack to ride. This is protocol compliance and defense in depth;
 * the authority boundary is the bearer-token scope, enforced downstream, not Origin.
 */
final class EnforceMcpOrigin
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $origin = trim((string) $request->headers->get('Origin', ''));

        if ($origin !== '' && !$this->isAllowedOrigin($origin)) {
            abort(403, 'Cross-origin MCP requests are not allowed.');
        }

        return $next($request);
    }

    private function isAllowedOrigin(string $origin): bool
    {
        $requestOrigin = OriginNormalizer::tryParse($origin)?->compact();
        $appOrigin = $this->applicationOrigin();

        return $requestOrigin !== null && $appOrigin !== null && $requestOrigin === $appOrigin;
    }

    /**
     * The only browser Origin accepted is the application's own — the MCP surface is
     * served there. Cross-origin browser access is deliberately unsupported: /mcp is
     * not a CORS path (config/cors.php is absent, so Laravel's defaults exclude it), so
     * a browser at any other host fails its Authorization preflight before reaching this
     * middleware. There is no origin allow-list to configure by design.
     */
    private function applicationOrigin(): ?string
    {
        $appUrl = config('app.url');

        return is_string($appUrl) ? OriginNormalizer::tryParse($appUrl)?->compact() : null;
    }
}
