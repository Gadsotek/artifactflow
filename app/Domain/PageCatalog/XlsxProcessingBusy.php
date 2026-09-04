<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

final class XlsxProcessingBusy extends XlsxProcessingRejected
{
    public function __construct(int $retryAfterSeconds)
    {
        parent::__construct(
            'XLSX processing is busy. Try again shortly.',
            $retryAfterSeconds,
        );
    }
}
