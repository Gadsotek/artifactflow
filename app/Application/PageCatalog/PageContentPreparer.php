<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use Closure;

final readonly class PageContentPreparer
{
    public function __construct(
        private PageContentStrategyRegistry $strategies,
        private PageContentStager $stager,
    ) {
    }

    public function validateInput(PageType $type, string $content): void
    {
        $this->strategies->for($type)->validateInput($type, $content);
    }

    public function validateSourceFilename(PageType $type, ?string $sourceFilename): void
    {
        $this->strategies->for($type)->validateSourceFilename($type, $sourceFilename);
    }

    public function prepare(
        PageType $type,
        string $content,
        string $actorUid,
        PageVersionSource $source,
    ): PreparedPageContent {
        return $this->strategies->for($type)->prepare($type, $content, $actorUid, $source);
    }

    public function prepareForPersistence(
        PageType $type,
        string $content,
        string $actorUid,
        PageVersionSource $source,
    ): PreparedPageContent {
        return $this->stager->stageForPersistence(
            $this->prepare($type, $content, $actorUid, $source),
        );
    }

    public function supportsSearchTextReindex(PageType $type): bool
    {
        return $this->strategies->for($type)->supportsSearchTextReindex($type);
    }

    /**
     * @param Closure(): ?string $content
     */
    public function textProjection(PageType $type, Closure $content): ?PageContentTextProjection
    {
        $strategy = $this->strategies->for($type);

        if (!$strategy->requiresContentForTextProjection($type)) {
            return $strategy->textProjection($type, '');
        }

        $storedContent = $content();

        return $storedContent === null
            ? null
            : $strategy->textProjection($type, $storedContent);
    }
}
