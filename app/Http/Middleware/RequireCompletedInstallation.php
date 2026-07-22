<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Application\Installation\InstallationReadiness;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireCompletedInstallation
{
    public function __construct(
        private InstallationReadiness $readiness,
        private AddSecurityHeaders $securityHeaders,
    ) {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Runtime-role enforcement is ordered before this middleware. Keep the
        // artifact host entirely outside installation UI and database checks, and
        // keep the stateless health probe usable while the installer is running.
        if (config('app.runtime_role') !== 'app' || $request->is('up')) {
            return $next($request);
        }

        if ($this->readiness->webSchemaIsReady()) {
            return $next($request);
        }

        $response = $request->is('mcp')
            ? $this->mcpUnavailableResponse($request)
            : response()->view('installation.required', status: Response::HTTP_SERVICE_UNAVAILABLE);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Retry-After', '30');

        return $this->securityHeaders->applyWithoutDatabaseConfiguration($request, $response);
    }

    private function mcpUnavailableResponse(Request $request): Response
    {
        $body = $request->json()->all();
        $id = $body['id'] ?? null;

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => -32000,
                'message' => 'ArtifactFlow is temporarily unavailable while database migrations are pending.',
                'data' => [
                    'type' => 'installation_not_ready',
                    'retryable' => true,
                    'operator_action' => 'Run make migrate, or make install for a guided first-time setup.',
                ],
            ],
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
