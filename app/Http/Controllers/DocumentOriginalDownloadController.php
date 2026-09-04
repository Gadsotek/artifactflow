<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PageCatalog\DocumentOriginalUrl;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Http\RedirectResponse;

final readonly class DocumentOriginalDownloadController
{
    public function current(Page $page, DocumentOriginalUrl $url): RedirectResponse
    {
        if (
            !in_array($page->type, [PageType::Xlsx, PageType::Docx], true)
            || !$url->enabledFor($page->type)
            || $page->current_version_uid === null
        ) {
            abort(404);
        }

        $version = PageVersion::query()
            ->whereKey($page->current_version_uid)
            ->where('page_uid', $page->uid)
            ->first();

        if (!$version instanceof PageVersion) {
            abort(404);
        }

        return $this->redirect($url->temporaryCurrentUrl($page, $version));
    }

    public function history(Page $page, PageVersion $version, DocumentOriginalUrl $url): RedirectResponse
    {
        if (
            !in_array($page->type, [PageType::Xlsx, PageType::Docx], true)
            || !$url->enabledFor($page->type)
            || $version->page_uid !== $page->uid
        ) {
            abort(404);
        }

        return $this->redirect($url->temporaryHistoryUrl($page, $version));
    }

    private function redirect(string $url): RedirectResponse
    {
        return redirect()->away($url)
            ->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
