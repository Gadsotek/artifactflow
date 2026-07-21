<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

use RuntimeException;

final class StalePageMetadataException extends RuntimeException
{
    public function __construct(
        public readonly int $currentRevision,
        public readonly int $submittedRevision,
    ) {
        parent::__construct('This page changed since you opened it.');
    }
}
