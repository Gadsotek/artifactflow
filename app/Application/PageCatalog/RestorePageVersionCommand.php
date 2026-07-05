<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

final readonly class RestorePageVersionCommand
{
    public function __construct(
        public string $pageUid,
        public string $versionUid,
        // Optimistic-concurrency guard: the version the caller observed as current.
        // When set, the append is rejected with a StalePageVersionException if the
        // page moved on under the page lock. The HTTP restore and revert paths always
        // supply it; null opts a programmatic caller out of the check.
        public ?string $expectedCurrentVersionUid = null,
    ) {
    }
}
