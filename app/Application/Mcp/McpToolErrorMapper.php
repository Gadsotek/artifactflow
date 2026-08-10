<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\ImageNormalizationRejected;
use App\Domain\PageCatalog\Security\BlockedPageContentException;
use App\Domain\PageCatalog\StalePageMetadataException;
use App\Domain\PageCatalog\StalePageVersionException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Shared exception-to-envelope mapping for the MCP write tools. Every write
 * tool surfaces the same application exceptions the human UI handles, so the
 * JSON-RPC error envelopes are produced in exactly one place.
 */
final class McpToolErrorMapper
{
    /**
     * @param callable(): McpToolResult $run
     * @param string|null $staleConflictMessage overrides the stale-version
     *                                          exception message when a tool
     *                                          documents its own conflict text
     */
    public function guard(
        callable $run,
        ?string $staleConflictMessage = null,
        McpNotFoundResource $authorizationResource = McpNotFoundResource::Page,
    ): McpToolResult {
        try {
            return $run();
        } catch (StalePageMetadataException $exception) {
            return McpToolResult::error([
                'type' => 'conflict',
                'message' => $exception->getMessage(),
                'retryable' => true,
                'current_metadata_revision' => $exception->currentRevision,
            ]);
        } catch (StalePageVersionException $exception) {
            return McpToolResult::error([
                'type' => 'conflict',
                'message' => $staleConflictMessage ?? $exception->getMessage(),
                'retryable' => true,
                'current_version_uid' => $exception->currentVersionUid,
            ]);
        } catch (BlockedPageContentException $exception) {
            return McpToolResult::error([
                'type' => 'blocked_content',
                'message' => $exception->getMessage(),
                'finding_codes' => $exception->findingCodes(),
            ]);
        } catch (ImageNormalizationRejected $exception) {
            return McpToolResult::error([
                'type' => 'temporarily_unavailable',
                'message' => $exception->getMessage(),
                'retryable' => true,
                'retry_after' => $exception->retryAfterSeconds,
            ]);
        } catch (AuthorizationException) {
            return McpToolResult::notFound($authorizationResource);
        } catch (DomainRuleViolation $exception) {
            return McpToolResult::error([
                'type' => 'invalid_request',
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
