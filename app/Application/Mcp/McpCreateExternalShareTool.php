<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\ExternalSharing\CreateExternalShare;
use App\Application\ExternalSharing\CreateExternalShareCommand;
use App\Application\ExternalSharing\ExternalShareUrl;
use App\Application\PageCatalog\PageAccess;
use App\Domain\DomainRuleViolation;
use App\Domain\ExternalSharing\ExternalShareMode;
use App\Models\Page;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

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
        McpToolArguments $arguments,
    ): McpToolResult {
        return $this->errors->guard(function () use ($actor, $arguments): McpToolResult {
            $mode = $this->mode($arguments);
            $expiresAt = $this->expiresAt($arguments, $mode);
            $page = $this->pages->editablePage($actor, $arguments->requiredString('page_uid'));

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
                    mode: $mode,
                    expiresAt: $expiresAt,
                ),
            );

            return McpToolResult::success([
                'share_uid' => $issued->share->uid,
                'page_uid' => $page->uid,
                'mode' => $issued->share->mode->value,
                'expires_at' => $issued->share->expires_at?->toISOString(),
                'created_at' => $issued->share->created_at->toISOString(),
                'url' => $this->urls->forIssuedShare($issued),
                'secret_presented_once' => true,
            ]);
        });
    }

    private function mode(McpToolArguments $arguments): ExternalShareMode
    {
        $mode = ExternalShareMode::tryFrom($arguments->requiredString('mode'));

        if (!$mode instanceof ExternalShareMode) {
            throw new DomainRuleViolation('Argument [mode] must be expires_at or one_time.');
        }

        return $mode;
    }

    private function expiresAt(
        McpToolArguments $arguments,
        ExternalShareMode $mode,
    ): ?CarbonImmutable {
        $value = $arguments->nullableString('expires_at');

        if ($mode === ExternalShareMode::OneTime) {
            if ($value !== null) {
                throw new DomainRuleViolation('One-time external shares cannot have an expiry.');
            }

            return null;
        }

        if ($value === null) {
            throw new DomainRuleViolation('Expiring external shares require an expiry.');
        }

        if (preg_match(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D',
            $value,
        ) !== 1) {
            throw new DomainRuleViolation(
                'Argument [expires_at] must be an ISO 8601 timestamp with a time-zone offset.',
            );
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw new DomainRuleViolation(
                'Argument [expires_at] must be a valid ISO 8601 timestamp.',
            );
        }
    }

    private function rateLimit(
        User $actor,
        Page $page,
    ): ?McpToolResult {
        $configuredLimit = config('rate_limits.external_share_creates_per_minute', 10);
        $limit = max(1, is_numeric($configuredLimit) ? (int) $configuredLimit : 10);
        $key = 'mcp-external-share-create:user:' . $actor->uid . ':page:' . $page->uid;

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return McpToolResult::error([
                'type' => 'rate_limited',
                'message' => 'External share creation rate limit exceeded.',
                'retry_after' => RateLimiter::availableIn($key),
            ]);
        }

        RateLimiter::hit($key, 60);

        return null;
    }
}
