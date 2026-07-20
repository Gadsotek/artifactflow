<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Administration\RealtimeConfiguration;
use App\Infrastructure\Security\OriginNormalizer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

final class AddSecurityHeaders
{
    public function __construct(
        private readonly RealtimeConfiguration $realtime,
    ) {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->isArtifactHostRuntime()) {
            $this->startCspNonce();
        }

        $response = $next($request);

        return $request->is('up')
            ? $this->applyWithoutDatabaseConfiguration($request, $response)
            : $this->apply($request, $response);
    }

    public function apply(Request $request, Response $response): Response
    {
        return $this->applyResponse($request, $response, includeDatabaseConfiguration: true);
    }

    /**
     * Apply the app policy without resolving settings stored in the database.
     * This is required for responses intentionally served before migrations.
     */
    public function applyWithoutDatabaseConfiguration(Request $request, Response $response): Response
    {
        return $this->applyResponse($request, $response, includeDatabaseConfiguration: false);
    }

    private function applyResponse(Request $request, Response $response, bool $includeDatabaseConfiguration): Response
    {
        if ($this->isArtifactHostRuntime()) {
            if (!$request->is('artifact-previews/*') || !$response->headers->has('Content-Security-Policy')) {
                $response->headers->set('Content-Security-Policy', $this->artifactHostFallbackPolicy());
            }
            // The artifact-preview response carries X-Frame-Options: DENY even though
            // the app must iframe it. That is safe and fails closed: the preview CSP's
            // frame-ancestors <app-origin> (set in ArtifactPreviewController) takes
            // precedence over X-Frame-Options in modern browsers, while every other
            // artifact-host response genuinely must not be framed. Do not "resolve"
            // the apparent contradiction by loosening frame-ancestors.
            $response->headers->set('X-Frame-Options', 'DENY');
            $response->headers->set('Strict-Transport-Security', $this->strictTransportSecurity());
            $response->headers->set('Referrer-Policy', 'no-referrer');
            $response->headers->set('X-Content-Type-Options', 'nosniff');

            return $response;
        }

        $this->ensureCspNonce();
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set(
            'Content-Security-Policy',
            $this->contentSecurityPolicy($includeDatabaseConfiguration),
        );
        $response->headers->set('Strict-Transport-Security', $this->strictTransportSecurity());
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // The app deliberately embeds a cross-origin sandboxed artifact iframe, so it
        // benefits from cross-origin isolation on the app window itself: COOP severs
        // opener/window relationships (cross-window scripting, XS-Leaks) and CORP
        // refuses cross-origin no-cors embedding of app responses. Neither governs
        // framing, so the app can still embed the isolated artifact host.
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        return $response;
    }

    private function contentSecurityPolicy(bool $includeDatabaseConfiguration): string
    {
        return implode('; ', [
            "default-src 'self'",
            'script-src ' . implode(' ', $this->scriptSources()),
            'style-src ' . implode(' ', $this->styleSources()),
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            'connect-src ' . implode(' ', $this->connectSources($includeDatabaseConfiguration)),
            "object-src 'none'",
            "base-uri 'none'",
            'form-action ' . $this->formActionSources(),
            'frame-src ' . $this->artifactFrameSource(),
            "webrtc 'block'",
            "frame-ancestors 'none'",
        ]);
    }

    private function artifactHostFallbackPolicy(): string
    {
        return implode('; ', [
            "default-src 'none'",
            "object-src 'none'",
            "base-uri 'none'",
            "form-action 'none'",
            "connect-src 'none'",
            "webrtc 'block'",
            "frame-ancestors 'none'",
            'sandbox',
        ]);
    }

    /**
     * @return list<string>
     */
    private function scriptSources(): array
    {
        return array_values(array_unique([
            "'self'",
            $this->nonceSource(),
            ...$this->localViteSources(),
        ]));
    }

    /**
     * @return list<string>
     */
    private function styleSources(): array
    {
        return array_values(array_unique([
            "'self'",
            $this->nonceSource(),
            ...$this->localViteSources(),
        ]));
    }

    /**
     * Mint a fresh nonce at the start of every request. Vite stores the nonce on a
     * framework singleton, so under FrankenPHP worker / Octane reuse a "generate
     * only when null" guard would recycle one request's nonce across later requests
     * and users, defeating the nonce CSP. Freshness must be a property of the
     * middleware, not of the classic-PHP process model the app happens to run today.
     */
    private function startCspNonce(): void
    {
        Vite::useCspNonce();
    }

    /**
     * Only used on the exception path, where handle() (and thus startCspNonce())
     * may not have run; any nonce already present there belongs to this request's
     * render, so keep it for header/markup consistency instead of minting a new one.
     */
    private function ensureCspNonce(): void
    {
        if (Vite::cspNonce() === null) {
            Vite::useCspNonce();
        }
    }

    private function nonceSource(): string
    {
        return "'nonce-" . Vite::cspNonce() . "'";
    }

    /**
     * @return list<string>
     */
    private function connectSources(bool $includeDatabaseConfiguration): array
    {
        return array_values(array_unique(array_filter([
            "'self'",
            ...$this->localViteConnectSources(),
            $includeDatabaseConfiguration ? $this->realtime->websocketOrigin() : null,
        ])));
    }

    /**
     * @return list<string>
     */
    private function localViteSources(): array
    {
        if ($this->isProduction()) {
            return [];
        }

        return $this->localViteOrigins();
    }

    /**
     * @return list<string>
     */
    private function localViteConnectSources(): array
    {
        if ($this->isProduction()) {
            return [];
        }

        return [
            ...$this->localViteOrigins(),
            ...array_map($this->webSocketOrigin(...), $this->localViteOrigins()),
        ];
    }

    /**
     * @return list<string>
     */
    private function localViteOrigins(): array
    {
        $configuredOrigin = $this->originFromUrl($this->stringConfig('app.local_vite_origin'));

        if ($configuredOrigin === null) {
            return [];
        }

        return [$configuredOrigin];
    }

    private function webSocketOrigin(string $origin): string
    {
        return str_starts_with($origin, 'https://')
            ? 'wss://' . substr($origin, strlen('https://'))
            : 'ws://' . substr($origin, strlen('http://'));
    }

    private function artifactFrameSource(): string
    {
        return $this->originFromUrl($this->stringConfig('app.artifact_url')) ?? "'none'";
    }

    /**
     * The create page submits the pre-save HTML draft to the isolated artifact
     * origin (form POST into the sandbox iframe), so that origin must be an
     * allowed form target alongside the app itself.
     */
    private function formActionSources(): string
    {
        $artifactOrigin = $this->originFromUrl($this->stringConfig('app.artifact_url'));

        return $artifactOrigin === null ? "'self'" : "'self' " . $artifactOrigin;
    }

    private function originFromUrl(string $url): ?string
    {
        return OriginNormalizer::tryParse($url)?->compact();
    }

    private function stringConfig(string $key): string
    {
        $value = config($key);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * A strong two-year max-age is always sent; includeSubDomains and preload are
     * opt-in (default off) because both reach past this host and are hard to undo --
     * see config/app.php. A self-hosting operator enables them once every subdomain
     * is HTTPS.
     */
    private function strictTransportSecurity(): string
    {
        $directives = ['max-age=' . $this->hstsMaxAge()];

        if ($this->boolConfig('app.hsts.include_subdomains')) {
            $directives[] = 'includeSubDomains';
        }

        if ($this->boolConfig('app.hsts.preload')) {
            $directives[] = 'preload';
        }

        return implode('; ', $directives);
    }

    private function hstsMaxAge(): int
    {
        $value = config('app.hsts.max_age', 63072000);

        return is_int($value) || is_string($value) ? max(0, (int) $value) : 63072000;
    }

    private function boolConfig(string $key): bool
    {
        $value = config($key);

        // Fall closed: a malformed HSTS flag must not silently switch on a
        // reach-beyond-this-host directive the operator never opted into.
        return is_bool($value) ? $value : false;
    }

    private function isProduction(): bool
    {
        return !in_array(strtolower(trim($this->stringConfig('app.env'))), ['local', 'testing'], true);
    }

    private function isArtifactHostRuntime(): bool
    {
        return $this->stringConfig('app.runtime_role') === 'artifact-host';
    }
}
