<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use App\Domain\PageCatalog\StalePageVersionException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;

/**
 * Shared exception-to-envelope mapping for the MCP write tools. Every write
 * tool surfaces the same application exceptions the human UI handles, so the
 * JSON-RPC error envelopes are produced in exactly one place.
 */
final readonly class McpToolErrorMapper
{
    public function __construct(
        private McpJsonRpc $jsonRpc,
    ) {
    }

    /**
     * @param callable(): JsonResponse $run
     * @param string|null $staleConflictMessage overrides the stale-version
     *                                          exception message when a tool
     *                                          documents its own conflict text
     */
    public function guard(mixed $id, callable $run, ?string $staleConflictMessage = null): JsonResponse
    {
        try {
            return $run();
        } catch (StalePageVersionException $exception) {
            return $this->jsonRpc->toolError($id, [
                'type' => 'conflict',
                'message' => $staleConflictMessage ?? $exception->getMessage(),
                'retryable' => true,
                'current_version_uid' => $exception->currentVersionUid,
            ]);
        } catch (BlockedPageContentException $exception) {
            return $this->jsonRpc->toolError($id, [
                'type' => 'blocked_content',
                'message' => $exception->getMessage(),
                'finding_codes' => $exception->findingCodes(),
            ]);
        } catch (AuthorizationException) {
            return $this->jsonRpc->notFound($id);
        } catch (DomainRuleViolation $exception) {
            return $this->jsonRpc->toolError($id, [
                'type' => 'invalid_request',
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
