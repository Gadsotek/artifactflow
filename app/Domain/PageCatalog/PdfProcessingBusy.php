<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

final class PdfProcessingBusy extends PdfProcessingRejected
{
    public function __construct(int $retryAfterSeconds)
    {
        parent::__construct(
            'PDF processing is busy. Try again shortly.',
            $retryAfterSeconds,
        );
    }
}
