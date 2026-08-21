<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

final class PdfProcessingUnavailable extends PdfProcessingRejected
{
    public function __construct()
    {
        parent::__construct(
            'PDF processing service is unavailable. Try again shortly.',
            5,
        );
    }
}
