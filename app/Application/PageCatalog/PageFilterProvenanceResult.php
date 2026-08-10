<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class PageFilterProvenanceResult
{
    /**
     * @param list<PageFilterProvenanceOption> $providers
     * @param list<PageFilterProvenanceOption> $models
     */
    public function __construct(
        public array $providers,
        public array $models,
    ) {
    }
}
