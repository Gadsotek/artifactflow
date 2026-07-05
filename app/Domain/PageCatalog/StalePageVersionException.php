<?php

declare(strict_types=1);

namespace App\Domain\PageCatalog;

use RuntimeException;

final class StalePageVersionException extends RuntimeException
{
    public function __construct(
        public readonly string $currentVersionUid,
        public readonly ?string $submittedBaseVersionUid,
    ) {
        parent::__construct('This page changed since you opened it.');
    }
}
