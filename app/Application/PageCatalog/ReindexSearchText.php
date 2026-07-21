<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\DomainRuleViolation;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final readonly class ReindexSearchText
{
    private const int DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private ArtifactContentReader $contentReader,
        private PageTextExtractor $textExtractor,
        private PageSearchVectorUpdater $searchVectors,
    ) {
    }

    public function handle(
        ?string $pageUid = null,
        bool $allVersions = false,
        bool $dryRun = false,
        int $batchSize = self::DEFAULT_BATCH_SIZE,
    ): ReindexSearchTextResult {
        if ($batchSize < 1) {
            throw new DomainRuleViolation('Search reindex batch size must be positive.');
        }

        $pageUid = $this->normalizedPageUid($pageUid);

        if ($pageUid !== null && !Page::query()->whereKey($pageUid)->exists()) {
            throw new DomainRuleViolation('Page does not exist.');
        }

        $hasSourceText = Schema::hasColumn('page_versions', 'source_text');
        $pagesProcessed = 0;
        $versionsExamined = 0;
        $versionsChanged = 0;
        $versionsSkipped = 0;

        $query = Page::query()
            ->when($pageUid !== null, static function (Builder $query) use ($pageUid): void {
                $query->whereKey($pageUid);
            })
            ->orderBy('uid');

        $query->chunkById(
            $batchSize,
            /**
             * @param EloquentCollection<int, Page> $pages
             */
            function (EloquentCollection $pages) use (
                $allVersions,
                $dryRun,
                $hasSourceText,
                &$pagesProcessed,
                &$versionsChanged,
                &$versionsExamined,
                &$versionsSkipped,
            ): void {
                foreach ($pages as $page) {
                    $result = $this->processPage($page, $allVersions, $dryRun, $hasSourceText);

                    $pagesProcessed++;
                    $versionsExamined += $result->versionsExamined;
                    $versionsChanged += $result->versionsChanged;
                    $versionsSkipped += $result->versionsSkipped;
                }
            },
            'uid',
        );

        return new ReindexSearchTextResult(
            pagesProcessed: $pagesProcessed,
            versionsExamined: $versionsExamined,
            versionsChanged: $versionsChanged,
            versionsSkipped: $versionsSkipped,
            dryRun: $dryRun,
        );
    }

    private function processPage(
        Page $page,
        bool $allVersions,
        bool $dryRun,
        bool $hasSourceText,
    ): ReindexSearchTextResult {
        return DB::transaction(function () use ($allVersions, $dryRun, $hasSourceText, $page): ReindexSearchTextResult {
            $versionsExamined = 0;
            $versionsChanged = 0;
            $versionsSkipped = 0;
            $lockedPage = Page::query()
                ->whereKey($page->uid)
                ->lockForUpdate()
                ->first();

            if (!$lockedPage instanceof Page) {
                return new ReindexSearchTextResult(
                    pagesProcessed: 1,
                    versionsExamined: 0,
                    versionsChanged: 0,
                    versionsSkipped: 0,
                    dryRun: $dryRun,
                );
            }

            foreach ($this->versionsFor($lockedPage, $allVersions) as $version) {
                $versionsExamined++;
                $content = $this->contentReader->read($version->content_storage_path);

                if ($content === null) {
                    $versionsSkipped++;

                    continue;
                }

                $derivedText = $this->derivedText($lockedPage, $version, $content, $hasSourceText);

                if (!$this->versionNeedsUpdate($version, $derivedText)) {
                    continue;
                }

                $versionsChanged++;

                if (!$dryRun) {
                    $version->forceFill($derivedText)->save();
                }
            }

            if (!$dryRun) {
                $this->searchVectors->refreshPage($lockedPage->uid);
            }

            return new ReindexSearchTextResult(
                pagesProcessed: 1,
                versionsExamined: $versionsExamined,
                versionsChanged: $versionsChanged,
                versionsSkipped: $versionsSkipped,
                dryRun: $dryRun,
            );
        });
    }

    /**
     * @return EloquentCollection<int, PageVersion>
     */
    private function versionsFor(Page $page, bool $allVersions): EloquentCollection
    {
        if ($allVersions) {
            return $page->versions()
                ->orderBy('version_number')
                ->get();
        }

        if ($page->current_version_uid === null) {
            return new EloquentCollection();
        }

        return PageVersion::query()
            ->whereKey($page->current_version_uid)
            ->get();
    }

    /**
     * Only the current version's extracted_text feeds the search vector and
     * snippets; historic versions deliberately keep it cleared (the version
     * writer drops it when a newer version becomes current) so reindexing
     * must not resurrect full-length copies for old versions.
     *
     * @return array{extracted_text: string|null, source_text?: string}
     */
    private function derivedText(Page $page, PageVersion $version, string $content, bool $hasSourceText): array
    {
        $isCurrentVersion = $page->current_version_uid === $version->uid;
        $derivedText = [
            // Cap the current version's extracted_text exactly like PageVersionWriter does.
            // An uncapped rewrite here bloats the row and makes versionNeedsUpdate() report a
            // phantom change on every oversized page (stored is capped, a fresh extraction is not).
            'extracted_text' => $isCurrentVersion
                ? mb_substr(
                    $this->textExtractor->extract($page->type, $content),
                    0,
                    PageSearchVectorUpdater::MAX_EXTRACTED_TEXT_SEARCH_CHARACTERS,
                )
                : null,
        ];

        if ($hasSourceText) {
            $derivedText['source_text'] = mb_substr(
                $this->textExtractor->extractSource($page->type, $content),
                0,
                PageSearchVectorUpdater::MAX_EXTRACTED_TEXT_SEARCH_CHARACTERS,
            );
        }

        return $derivedText;
    }

    /**
     * @param array{extracted_text: string|null, source_text?: string} $derivedText
     */
    private function versionNeedsUpdate(PageVersion $version, array $derivedText): bool
    {
        foreach ($derivedText as $column => $value) {
            if ($version->getAttribute($column) !== $value) {
                return true;
            }
        }

        return false;
    }

    private function normalizedPageUid(?string $pageUid): ?string
    {
        if ($pageUid === null) {
            return null;
        }

        $normalizedPageUid = trim($pageUid);

        if ($normalizedPageUid === '') {
            return null;
        }

        return $normalizedPageUid;
    }
}
