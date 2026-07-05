<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Mcp\McpAccessTokenIssuer;
use App\Application\Mcp\McpCreateCategoryTool;
use App\Application\Mcp\McpCreateTagTool;
use App\Application\Mcp\McpCreateTool;
use App\Application\Mcp\McpJsonRpc;
use App\Application\Mcp\McpListTaxonomyTool;
use App\Application\Mcp\McpListWorkspacesTool;
use App\Application\Mcp\McpReadTool;
use App\Application\Mcp\McpRequestContext;
use App\Application\Mcp\McpRevertTool;
use App\Application\Mcp\McpSearchTool;
use App\Application\Mcp\McpToolArguments;
use App\Application\Mcp\McpToolCatalog;
use App\Application\Mcp\McpToolGuard;
use App\Application\Mcp\McpUpdateTool;
use App\Domain\DomainRuleViolation;
use App\Models\McpAccessToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON-RPC entry point for the MCP tool surface. The controller only parses
 * and dispatches JSON-RPC, resolves the authenticated MCP actor, and shapes
 * envelopes; every tool body lives in its own App\Application\Mcp handler
 * (McpSearchTool, McpReadTool, McpCreateTool, ...) behind the shared
 * McpToolGuard scope/rate-limit gate and McpToolErrorMapper. Write tools
 * delegate to the same application handlers, policies, scanners,
 * optimistic-concurrency checks, and audit trail as the human UI.
 */
final readonly class McpController
{
    private const string PROTOCOL_VERSION = '2025-03-26';

    private const string SERVER_VERSION = '0.1.0';

    public function __construct(
        private McpRequestContext $mcpContext,
        private McpJsonRpc $jsonRpc,
        private McpToolCatalog $toolCatalog,
        private McpToolGuard $guard,
        private McpListWorkspacesTool $listWorkspacesTool,
        private McpListTaxonomyTool $listTaxonomyTool,
        private McpSearchTool $searchTool,
        private McpReadTool $readTool,
        private McpCreateTool $createTool,
        private McpCreateCategoryTool $createCategoryTool,
        private McpCreateTagTool $createTagTool,
        private McpUpdateTool $updateTool,
        private McpRevertTool $revertTool,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        try {
            $body = $request->json()->all();

            // JSON-RPC notifications carry no "id" member and MUST NOT receive a
            // response body. Under the Streamable HTTP transport a notification-only
            // POST is acknowledged with 202 and no body. This covers the mandatory
            // notifications/initialized message a conforming client sends after
            // initialize, which previously fell through to a -32601 error at HTTP 200
            // and broke the lifecycle handshake.
            if (!array_key_exists('id', $body)) {
                return response()->noContent(202);
            }

            $id = $body['id'];
            $method = $body['method'] ?? null;

            if (!is_string($method)) {
                return $this->jsonRpc->error($id, -32600, 'Invalid MCP request.');
            }

            if ($method === 'tools/call') {
                try {
                    return $this->callTool($request, $id, McpToolArguments::stringKeyed($body['params'] ?? [], 'params'));
                } catch (DomainRuleViolation $exception) {
                    return $this->jsonRpc->error($id, -32602, $exception->getMessage());
                }
            }

            return match ($method) {
                'initialize' => $this->jsonRpc->result($id, [
                    'protocolVersion' => self::PROTOCOL_VERSION,
                    'serverInfo' => [
                        'name' => 'artifactflow',
                        'version' => self::SERVER_VERSION,
                    ],
                    'capabilities' => [
                        'tools' => [
                            'listChanged' => false,
                        ],
                    ],
                ]),
                'tools/list' => $this->jsonRpc->result($id, [
                    'tools' => $this->toolCatalog->toolDefinitions(),
                ]),
                default => $this->jsonRpc->error($id, -32601, 'Unknown MCP method.'),
            };
        } finally {
            $this->mcpContext->clear();
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function callTool(Request $request, mixed $id, array $params): JsonResponse
    {
        $actor = $request->user('mcp');
        $token = $request->attributes->get('mcp_access_token');

        if (!$actor instanceof User || !$token instanceof McpAccessToken) {
            abort(401);
        }

        $toolName = $params['name'] ?? null;
        $argumentValue = $params['arguments'] ?? [];

        if (!is_string($toolName) || !is_array($argumentValue)) {
            return $this->jsonRpc->toolError($id, [
                'type' => 'invalid_request',
                'message' => 'Tool name and arguments are required.',
            ]);
        }

        try {
            $arguments = McpToolArguments::fromValue($argumentValue, 'arguments');
            $this->activateMcpContext($request, $token);

            return match ($toolName) {
                'list_workspaces' => $this->guard->run($id, $token, McpAccessTokenIssuer::SCOPE_SEARCH, false, fn (): JsonResponse => $this->listWorkspacesTool->handle($id, $actor, $token)),
                'list_taxonomy' => $this->guard->run($id, $token, McpAccessTokenIssuer::SCOPE_SEARCH, false, fn (): JsonResponse => $this->listTaxonomyTool->handle($id, $actor, $arguments)),
                'search' => $this->guard->run($id, $token, McpAccessTokenIssuer::SCOPE_SEARCH, false, fn (): JsonResponse => $this->searchTool->handle($id, $actor, $token, $arguments)),
                'read' => $this->guard->run($id, $token, McpAccessTokenIssuer::SCOPE_READ, false, fn (): JsonResponse => $this->readTool->handle($id, $actor, $arguments)),
                'create' => $this->guard->run($id, $token, McpAccessTokenIssuer::SCOPE_CREATE, true, fn (): JsonResponse => $this->createTool->handle($id, $actor, $arguments)),
                'create_category' => $this->guard->run($id, $token, McpAccessTokenIssuer::SCOPE_CREATE, true, fn (): JsonResponse => $this->createCategoryTool->handle($id, $actor, $arguments)),
                'create_tag' => $this->guard->run($id, $token, McpAccessTokenIssuer::SCOPE_CREATE, true, fn (): JsonResponse => $this->createTagTool->handle($id, $actor, $arguments)),
                'update' => $this->guard->run($id, $token, McpAccessTokenIssuer::SCOPE_UPDATE, true, fn (): JsonResponse => $this->updateTool->handle($id, $actor, $arguments)),
                'revert' => $this->guard->run($id, $token, McpAccessTokenIssuer::SCOPE_UPDATE, true, fn (): JsonResponse => $this->revertTool->handle($id, $actor, $arguments)),
                default => $this->jsonRpc->toolError($id, [
                    'type' => 'unknown_tool',
                    'message' => 'Unknown MCP tool.',
                ]),
            };
        } catch (DomainRuleViolation $exception) {
            return $this->jsonRpc->toolError($id, [
                'type' => 'invalid_request',
                'message' => $exception->getMessage(),
            ]);
        } finally {
            $this->mcpContext->clear();
        }
    }

    private function activateMcpContext(Request $request, McpAccessToken $token): void
    {
        $sessionId = $request->header('Mcp-Agent-Session');
        $this->mcpContext->activate($token, is_string($sessionId) ? $sessionId : null);
    }
}
