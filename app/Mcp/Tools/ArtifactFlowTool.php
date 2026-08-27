<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Application\Mcp\McpPayloadEncoder;
use App\Application\Mcp\McpRequestContext;
use App\Application\Mcp\McpToolArguments;
use App\Application\Mcp\McpToolError;
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
use Laravel\Mcp\ResponseFactory;
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
        private readonly McpPayloadEncoder $payloadEncoder,
    ) {
    }

    /**
     * @param Closure(User, McpAccessToken, McpToolArguments): McpToolResult $run
     * @param string|list<string> $scopes
     *
     * @throws AuthenticationException
     * @throws JsonException
     */
    final protected function invoke(
        Request $request,
        string|array $scopes,
        bool $rateLimited,
        Closure $run,
    ): Response|ResponseFactory {
        $actor = $request->user('mcp');
        $token = $this->httpRequest->attributes->get('mcp_access_token');

        if (!$actor instanceof User || !$token instanceof McpAccessToken) {
            throw new AuthenticationException('Unauthenticated.');
        }

        try {
            $arguments = McpToolArguments::fromValue($request->all(), 'arguments');
        } catch (DomainRuleViolation $exception) {
            return $this->response(McpToolResult::error(McpToolError::invalidRequest(
                $exception->getMessage(),
            )));
        }

        $sessionId = $request->sessionId();

        if ($sessionId === null || $sessionId === '') {
            $legacySessionId = $this->httpRequest->header('Mcp-Agent-Session');
            $sessionId = is_string($legacySessionId) ? $legacySessionId : null;
        }

        try {
            $result = $this->guard->run(
                $token,
                $scopes,
                $rateLimited,
                function (McpAccessToken $liveToken) use ($arguments, $run, $sessionId): McpToolResult {
                    $this->mcpContext->activate($liveToken, $sessionId);

                    try {
                        return $run($liveToken->principal, $liveToken, $arguments);
                    } finally {
                        $this->mcpContext->clear();
                    }
                },
            );
        } catch (DomainRuleViolation $exception) {
            $result = McpToolResult::error(McpToolError::invalidRequest($exception->getMessage()));
        }

        return $this->response($result);
    }

    /**
     * @throws JsonException
     */
    private function response(McpToolResult $result): Response|ResponseFactory
    {
        $json = json_encode(
            $this->payloadEncoder->encode($result->payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($result->isError) {
            return Response::error($json);
        }

        $text = Response::text($json);

        if ($result->image === null) {
            return $text;
        }

        return Response::make([
            $text,
            Response::image($result->image->bytes, $result->image->mediaType),
        ]);
    }
}
