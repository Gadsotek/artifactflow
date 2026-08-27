<?php

declare(strict_types=1);

namespace App\Application\Mcp\Input;

use App\Application\Mcp\McpToolArguments;
use App\Domain\DomainRuleViolation;
use App\Domain\ExternalSharing\ExternalShareMode;
use Carbon\CarbonImmutable;
use Throwable;

final readonly class McpCreateExternalShareInput
{
    private function __construct(
        public string $pageUid,
        public ExternalShareMode $mode,
        public ?CarbonImmutable $expiresAt,
    ) {
    }

    public static function fromArguments(McpToolArguments $arguments): self
    {
        $mode = ExternalShareMode::tryFrom($arguments->requiredString('mode'));

        if (!$mode instanceof ExternalShareMode) {
            throw new DomainRuleViolation('Argument [mode] must be expires_at or one_time.');
        }

        $expiresAt = self::expiresAt($arguments->nullableString('expires_at'), $mode);

        return new self(
            pageUid: $arguments->requiredString('page_uid'),
            mode: $mode,
            expiresAt: $expiresAt,
        );
    }

    private static function expiresAt(?string $value, ExternalShareMode $mode): ?CarbonImmutable
    {
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
}
