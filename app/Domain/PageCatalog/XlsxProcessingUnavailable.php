<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

final class XlsxProcessingUnavailable extends XlsxProcessingRejected
{
    public function __construct()
    {
        parent::__construct(
            'XLSX processing service is unavailable. Try again shortly.',
            5,
        );
    }
}
