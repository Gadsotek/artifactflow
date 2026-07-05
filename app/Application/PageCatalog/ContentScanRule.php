<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\ContentFindingSeverity;
use App\Domain\PageCatalog\PageType;
use Closure;

/**
 * One declarative content-scan rule: a matcher plus the finding it produces.
 * Rules replace the long flat if/preg_match blocks so adding a pattern is a
 * single table entry. Scanning is advisory and best-effort (see
 * PageContentScanner); a clean scan is never proof that no secret was stored.
 */
final readonly class ContentScanRule
{
    /**
     * @param Closure(string): bool $matcher
     */
    public function __construct(
        public ContentFindingSeverity $severity,
        public string $code,
        public string $message,
        private Closure $matcher,
        public ?PageType $onlyForType = null,
    ) {
    }

    /**
     * @param non-empty-string $pattern
     */
    public static function pattern(
        ContentFindingSeverity $severity,
        string $code,
        string $message,
        string $pattern,
        ?PageType $onlyForType = null,
    ): self {
        return new self(
            severity: $severity,
            code: $code,
            message: $message,
            matcher: static fn (string $content): bool => preg_match($pattern, $content) === 1,
            onlyForType: $onlyForType,
        );
    }

    public function appliesTo(?PageType $type): bool
    {
        return $this->onlyForType === null || $this->onlyForType === $type;
    }

    public function matches(string $content): bool
    {
        return ($this->matcher)($content);
    }

    public function toFinding(): ContentSecurityFinding
    {
        return new ContentSecurityFinding(
            severity: $this->severity,
            code: $this->code,
            message: $this->message,
        );
    }
}
