<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Models\Page;
use App\Models\PageVersion;
use Closure;

/**
 * A one-way capability issued after content normalization and scanning. It does
 * not expose the prepared bytes, and no persistence API accepts a caller-built
 * PreparedPageContent value.
 */
final readonly class PreparedPageVersionAppend
{
    /**
     * @param Closure(Page, ?string, ?string): PageVersion $append
     */
    public function __construct(
        private Closure $append,
    ) {
    }

    public function append(
        Page $page,
        ?string $baseVersionUid = null,
        ?string $expectedCurrentVersionUid = null,
    ): PageVersion {
        return ($this->append)($page, $baseVersionUid, $expectedCurrentVersionUid);
    }
}
