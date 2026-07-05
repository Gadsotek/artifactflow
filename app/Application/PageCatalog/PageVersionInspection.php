<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use Illuminate\Support\Facades\Log;

final readonly class PageVersionInspection
{
    public function __construct(
        private ArtifactContentReader $contentReader,
        private ArtifactPreviewUrl $artifactPreviewUrls,
        private MarkdownPageRenderer $markdownRenderer,
        private PageAccess $access,
        private PageVersionDiff $diff,
    ) {
    }

    public function forVersion(User $actor, Page $page, PageVersion $version): PageVersionInspectionData
    {
        $this->access->ensureCanView($actor, $page);

        if ($version->page_uid !== $page->uid || $page->current_version_uid === null) {
            abort(404);
        }

        $version->loadMissing('creator');

        $currentVersion = PageVersion::query()
            ->whereKey($page->current_version_uid)
            ->where('page_uid', $page->uid)
            ->first();

        if (!$currentVersion instanceof PageVersion) {
            abort(404);
        }

        $selectedSource = $this->contentReader->read($version->content_storage_path);
        $currentSource = $version->uid === $currentVersion->uid
            ? $selectedSource
            : $this->contentReader->read($currentVersion->content_storage_path);
        $renderedMarkdown = null;
        $artifactPreviewUrl = null;

        if ($selectedSource !== null && $page->type === PageType::Markdown) {
            $renderedMarkdown = $this->markdownRenderer->renderForPage($actor, $page, $selectedSource);
        }

        if ($selectedSource !== null && $page->type === PageType::HtmlArtifact) {
            $artifactPreviewUrl = $this->artifactPreviewUrls->temporaryHistoryUrl($page, $version);
            Log::info('artifact_history_preview_url.issued', [
                'actor_user_uid' => $actor->uid,
                'page_uid' => $page->uid,
                'version_uid' => $version->uid,
            ]);
        }

        return new PageVersionInspectionData(
            version: $version,
            currentVersion: $currentVersion,
            olderVersion: $this->adjacentVersion($page, $version, newer: false),
            newerVersion: $this->adjacentVersion($page, $version, newer: true),
            renderedMarkdown: $renderedMarkdown,
            artifactPreviewUrl: $artifactPreviewUrl,
            contentUnavailable: $selectedSource === null,
            comparisonUnavailable: $selectedSource === null || $currentSource === null,
            diff: $selectedSource === null || $currentSource === null
                ? new PageVersionDiffResult([], 0, 0, false)
                : $this->diff->compare($selectedSource, $currentSource),
            canRestore: $version->uid !== $currentVersion->uid
                && $page->status !== PageStatus::Archived
                && $this->access->canEdit($actor, $page),
        );
    }

    private function adjacentVersion(Page $page, PageVersion $version, bool $newer): ?PageVersion
    {
        $query = PageVersion::query()->where('page_uid', $page->uid);

        if ($newer) {
            $query->where('version_number', '>', $version->version_number)->orderBy('version_number');
        } else {
            $query->where('version_number', '<', $version->version_number)->orderByDesc('version_number');
        }

        return $query->first();
    }
}
