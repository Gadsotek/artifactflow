<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\ExternalSharing\CreateExternalShare;
use App\Application\ExternalSharing\CreateExternalShareCommand;
use App\Application\ExternalSharing\ExternalShareUrl;
use App\Application\Mcp\Input\McpCreateExternalShareInput;
use App\Application\Mcp\Output\McpExternalShareCreatedPayload;
use App\Application\PageCatalog\PageAccess;
use App\Models\Page;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;

final readonly class McpCreateExternalShareTool
{
    public function __construct(
        private McpPageResolver $pages,
        private PageAccess $access,
        private CreateExternalShare $createExternalShare,
        private ExternalShareUrl $urls,
        private McpToolErrorMapper $errors,
    ) {
    }

    public function handle(
        User $actor,
        McpCreateExternalShareInput $input,
    ): McpToolResult {
        return $this->errors->guard(function () use ($actor, $input): McpToolResult {
            $page = $this->pages->editablePage($actor, $input->pageUid);

            if (!$page instanceof Page || !$this->access->canShareOwnedPageViaMcp($actor, $page)) {
                return McpToolResult::notFound();
            }

            $rateLimitError = $this->rateLimit($actor, $page);

            if ($rateLimitError instanceof McpToolResult) {
                return $rateLimitError;
            }

            $issued = $this->createExternalShare->handleForMcp(
                $actor,
                new CreateExternalShareCommand(
                    pageUid: $page->uid,
                    mode: $input->mode,
                    expiresAt: $input->expiresAt,
                ),
            );

            return McpToolResult::success(new McpExternalShareCreatedPayload(
                shareUid: $issued->share->uid,
                pageUid: $page->uid,
                mode: $issued->share->mode->value,
                expiresAt: $issued->share->expires_at?->toISOString(),
                createdAt: $issued->share->created_at->toISOString()
                    ?? throw new LogicException('Issued external share has no creation timestamp.'),
                url: $this->urls->forIssuedShare($issued),
            ));
        });
    }

    private function rateLimit(
        User $actor,
        Page $page,
    ): ?McpToolResult {
        $configuredLimit = config('rate_limits.external_share_creates_per_minute', 10);
        $limit = max(1, is_numeric($configuredLimit) ? (int) $configuredLimit : 10);
        $key = 'mcp-external-share-create:user:' . $actor->uid . ':page:' . $page->uid;

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return McpToolResult::error(McpToolError::rateLimited(
                'External share creation rate limit exceeded.',
                RateLimiter::availableIn($key),
            ));
        }

        RateLimiter::hit($key, 60);

        return null;
    }
}
