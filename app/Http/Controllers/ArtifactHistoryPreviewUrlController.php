<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PageCatalog\ArtifactPreviewUrl;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

final class ArtifactHistoryPreviewUrlController
{
    public function __invoke(Page $page, PageVersion $version, ArtifactPreviewUrl $previewUrl): JsonResponse
    {
        if ($page->type !== PageType::HtmlArtifact || $version->page_uid !== $page->uid) {
            abort(404);
        }

        Log::info('artifact_history_preview_url.refreshed', [
            'page_uid' => $page->uid,
            'version_uid' => $version->uid,
        ]);

        return response()
            ->json(['url' => $previewUrl->temporaryHistoryUrl($page, $version)])
            ->header('Cache-Control', 'no-store, private');
    }
}
