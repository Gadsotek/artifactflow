<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\ContentFindingSeverity;

final readonly class ContentSecurityFinding
{
    public function __construct(
        public ContentFindingSeverity $severity,
        public string $code,
        public string $message,
    ) {
    }

    /**
     * @return array{severity: string, code: string, message: string}
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity->value,
            'code' => $this->code,
            'message' => $this->message,
        ];
    }
}
