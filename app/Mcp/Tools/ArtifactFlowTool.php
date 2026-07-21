<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Application\Mcp\McpRequestContext;
use App\Application\Mcp\McpToolArguments;
use App\Application\Mcp\McpToolGuard;
use App\Application\Mcp\McpToolResult;
use App\Domain\DomainRuleViolation;
use App\Models\McpAccessToken;
use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request as HttpRequest;
use JsonException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Shared framework adapter for ArtifactFlow tools. Business authorization and
 * write throttling remain in the application layer; Laravel MCP owns framing.
 */
abstract class ArtifactFlowTool extends Tool
{
    public function __construct(
        private readonly McpRequestContext $mcpContext,
        private readonly McpToolGuard $guard,
        private readonly HttpRequest $httpRequest,
    ) {
    }

    /**
     * @param Closure(User, McpAccessToken, McpToolArguments): McpToolResult $run
     *
     * @throws AuthenticationException
     * @throws JsonException
     */
    final protected function invoke(Request $request, string $scope, bool $rateLimited, Closure $run): Response
    {
        $actor = $request->user('mcp');
        $token = $this->httpRequest->attributes->get('mcp_access_token');

        if (!$actor instanceof User || !$token instanceof McpAccessToken) {
            throw new AuthenticationException('Unauthenticated.');
        }

        try {
            $arguments = McpToolArguments::fromValue($request->all(), 'arguments');
        } catch (DomainRuleViolation $exception) {
            return $this->response(McpToolResult::error([
                'type' => 'invalid_request',
                'message' => $exception->getMessage(),
            ]));
        }

        $sessionId = $request->sessionId();

        if ($sessionId === null || $sessionId === '') {
            $legacySessionId = $this->httpRequest->header('Mcp-Agent-Session');
            $sessionId = is_string($legacySessionId) ? $legacySessionId : null;
        }

        $this->mcpContext->activate($token, $sessionId);

        try {
            try {
                $result = $this->guard->run(
                    $token,
                    $scope,
                    $rateLimited,
                    static fn (): McpToolResult => $run($actor, $token, $arguments),
                );
            } catch (DomainRuleViolation $exception) {
                $result = McpToolResult::error([
                    'type' => 'invalid_request',
                    'message' => $exception->getMessage(),
                ]);
            }

            return $this->response($result);
        } finally {
            $this->mcpContext->clear();
        }
    }

    /**
     * @throws JsonException
     */
    private function response(McpToolResult $result): Response
    {
        $json = json_encode(
            $result->payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return $result->isError ? Response::error($json) : Response::text($json);
    }
}
