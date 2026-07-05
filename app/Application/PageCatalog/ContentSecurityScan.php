<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class ContentSecurityScan
{
    /**
     * @param list<ContentSecurityFinding> $blockedFindings
     * @param list<ContentSecurityFinding> $warningFindings
     */
    public function __construct(
        public array $blockedFindings,
        public array $warningFindings,
    ) {
    }

    public function hasBlockedFindings(): bool
    {
        return $this->blockedFindings !== [];
    }

    public function hasWarningFindings(): bool
    {
        return $this->warningFindings !== [];
    }

    /**
     * @return list<string>
     */
    public function blockedCodes(): array
    {
        return array_map(
            static fn (ContentSecurityFinding $finding): string => $finding->code,
            $this->blockedFindings,
        );
    }

    /**
     * @return list<string>
     */
    public function warningCodes(): array
    {
        return array_map(
            static fn (ContentSecurityFinding $finding): string => $finding->code,
            $this->warningFindings,
        );
    }

    /**
     * @return list<array{severity: string, code: string, message: string}>
     */
    public function persistedFindings(): array
    {
        return array_map(
            static fn (ContentSecurityFinding $finding): array => $finding->toArray(),
            $this->warningFindings,
        );
    }
}
