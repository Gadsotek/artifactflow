<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class PageFilterProvenanceOption
{
    public function __construct(
        public string $value,
        public string $label,
    ) {
    }
}
