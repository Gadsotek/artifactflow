<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Infrastructure\Security\OriginNormalizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceRuntimeRoleSurface
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isArtifactPreview = $request->is('artifact-previews/*');
        $runtimeRole = config('app.runtime_role');
        $isArtifactHost = $runtimeRole === 'artifact-host';

        if ($runtimeRole !== 'app' && !$isArtifactHost) {
            abort(404);
        }

        if ($isArtifactPreview !== $isArtifactHost) {
            abort(404);
        }

        // The path/role split above stops the app process from serving artifact
        // bytes and the artifact process from serving app routes, but a request
        // reaches this process by *host* routing the operator controls. It does not
        // stop the app process from answering its own application routes (login,
        // MCP, admin) when an operator misroutes the artifact hostname to the app
        // service (or pools both behind it). Serving login there would mint a
        // host-only session cookie scoped to the artifact hostname, which a later
        // request to the real artifact host would then carry -- collapsing the
        // no-app-cookies-on-the-artifact-origin invariant. So the app runtime
        // refuses any request that arrives on the artifact host, before the session
        // middleware runs, so no such cookie is ever emitted.
        //
        // /up is exempt: Docker probes it on the container's loopback address, which
        // equals the artifact host in the shipped local setup, and it starts no
        // session and returns only "OK".
        //
        // The mirror direction (artifact runtime answering the app hostname) needs
        // no check here: the artifact runtime serves only /artifact-previews/*, the
        // saved-preview controller already refuses any request whose origin is not
        // the artifact origin, and the draft receiver verifies an artifact-origin-
        // bound capability before rendering under an opaque sandbox.
        if (!$isArtifactHost && !$request->is('up') && $this->requestTargetsArtifactHost($request)) {
            abort(404);
        }

        return $next($request);
    }

    private function requestTargetsArtifactHost(Request $request): bool
    {
        $configuredArtifactUrl = config('app.artifact_url');
        $artifactHost = OriginNormalizer::tryHost(is_string($configuredArtifactUrl) ? $configuredArtifactUrl : '');

        // Nothing to enforce when the artifact origin is unconfigured/unparseable:
        // the production boot gate already guarantees a valid, distinct artifact_url.
        if ($artifactHost === null) {
            return false;
        }

        return OriginNormalizer::tryHost($request->getHost()) === $artifactHost;
    }
}
