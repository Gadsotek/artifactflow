<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\ImageNormalizationRejected;
use App\Domain\PageCatalog\PdfProcessingRejected;
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
            return McpToolResult::error(McpToolError::metadataConflict(
                $exception->getMessage(),
                $exception->currentRevision,
            ));
        } catch (StalePageVersionException $exception) {
            return McpToolResult::error(McpToolError::versionConflict(
                $staleConflictMessage ?? $exception->getMessage(),
                $exception->currentVersionUid,
            ));
        } catch (BlockedPageContentException $exception) {
            return McpToolResult::error(McpToolError::blockedContent(
                $exception->getMessage(),
                $exception->findingCodes(),
            ));
        } catch (ImageNormalizationRejected $exception) {
            return McpToolResult::error(McpToolError::temporarilyUnavailable(
                $exception->getMessage(),
                $exception->retryAfterSeconds,
            ));
        } catch (PdfProcessingRejected $exception) {
            return McpToolResult::error(McpToolError::temporarilyUnavailable(
                $exception->getMessage(),
                $exception->retryAfterSeconds,
            ));
        } catch (AuthorizationException) {
            return McpToolResult::notFound($authorizationResource);
        } catch (DomainRuleViolation $exception) {
            return McpToolResult::error(McpToolError::invalidRequest($exception->getMessage()));
        }
    }
}
