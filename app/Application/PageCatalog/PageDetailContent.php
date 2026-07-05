<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\User;
use Illuminate\Support\Facades\Log;

final readonly class PageDetailContent
{
    public function __construct(
        private ArtifactContentReader $contentReader,
        private ArtifactPreviewUrl $artifactPreviewUrls,
        private MarkdownPageRenderer $markdownRenderer,
    ) {
    }

    public function forPage(User $actor, Page $page, bool $canMutateContent): PageDetailContentData
    {
        $version = $page->current_version_uid === null
            ? null
            : PageVersion::query()->find($page->current_version_uid);

        $sourcePreview = null;
        $renderedMarkdown = null;
        $renderedEditorMarkdown = null;
        $artifactPreviewUrl = null;
        $contentUnavailable = false;

        if ($version instanceof PageVersion) {
            if ($page->type === PageType::Markdown) {
                $markdownSource = $this->contentReader->read($version->content_storage_path);

                if ($markdownSource !== null) {
                    $baseRenderedMarkdown = $this->markdownRenderer->render($markdownSource);
                    $renderedMarkdown = $this->markdownRenderer->resolveWikiLinksForPage(
                        actor: $actor,
                        page: $page,
                        renderedHtml: $baseRenderedMarkdown,
                    );

                    if ($canMutateContent) {
                        $sourcePreview = $markdownSource;
                        $renderedEditorMarkdown = $baseRenderedMarkdown;
                    }
                } else {
                    $contentUnavailable = true;
                }
            } elseif ($canMutateContent) {
                $htmlSource = $this->contentReader->read($version->content_storage_path);

                if ($htmlSource !== null) {
                    $sourcePreview = $htmlSource;
                } else {
                    $contentUnavailable = true;
                }
            }
        }

        if ($version instanceof PageVersion && $page->type === PageType::HtmlArtifact) {
            $artifactPreviewUrl = $this->artifactPreviewUrls->temporaryUrl($page, $version);
            Log::info('artifact_preview_url.issued', [
                'actor_user_uid' => $actor->uid,
                'page_uid' => $page->uid,
                'version_uid' => $version->uid,
            ]);
        }

        return new PageDetailContentData(
            version: $version,
            sourcePreview: $sourcePreview,
            renderedMarkdown: $renderedMarkdown,
            renderedEditorMarkdown: $renderedEditorMarkdown,
            artifactPreviewUrl: $artifactPreviewUrl,
            contentUnavailable: $contentUnavailable,
        );
    }
}
