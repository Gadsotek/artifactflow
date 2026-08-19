<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;

interface PageContentStrategy
{
    /**
     * Multiple textual formats may deliberately share one strategy. Binary
     * formats with their own parser boundary register a dedicated strategy.
     *
     * @return non-empty-list<PageType>
     */
    public function supportedTypes(): array;

    /**
     * Cheap validation that is safe to run before metadata lookup.
     */
    public function validateInput(PageType $type, string $content): void;

    public function validateSourceFilename(PageType $type, ?string $sourceFilename): void;

    public function prepare(
        PageType $type,
        string $content,
        string $actorUid,
        PageVersionSource $source,
    ): PreparedPageContent;

    public function requiresContentForTextProjection(PageType $type): bool;

    /**
     * Whether the generic in-transaction search maintenance path may rebuild
     * this format's text projection. External processors need a dedicated
     * two-phase reprocessing use case and therefore return false.
     */
    public function supportsSearchTextReindex(PageType $type): bool;

    /**
     * Rebuild the derived database text for already stored content.
     */
    public function textProjection(PageType $type, string $content): PageContentTextProjection;
}
