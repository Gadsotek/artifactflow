<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

final class DocxProcessingBusy extends DocxProcessingRejected
{
    public function __construct(int $retryAfterSeconds)
    {
        parent::__construct('Word document processing is busy. Try again shortly.', $retryAfterSeconds);
    }
}
