<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

use App\Domain\DomainRuleViolation;

abstract class XlsxProcessingRejected extends DomainRuleViolation
{
    public function __construct(
        string $message,
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct($message);
    }
}
