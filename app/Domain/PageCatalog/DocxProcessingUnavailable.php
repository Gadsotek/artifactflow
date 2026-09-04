<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

final class DocxProcessingUnavailable extends DocxProcessingRejected
{
    public function __construct()
    {
        parent::__construct('Word document processing service is unavailable. Try again shortly.', 5);
    }
}
