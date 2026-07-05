<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PageCatalog\PageVersionInspection;
use App\Models\Page;
use App\Models\PageVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PageVersionInspectionController
{
    use Concerns\ResolvesAuthenticatedUser;

    public function __invoke(
        Request $request,
        Page $page,
        PageVersion $version,
        PageVersionInspection $inspection,
    ): Response {
        $user = $this->authenticatedUser($request);

        if ($version->page_uid !== $page->uid) {
            abort(404);
        }

        return response()
            ->view('pages.versions.show', [
                'inspection' => $inspection->forVersion($user, $page, $version),
                'page' => $page,
            ])
            ->header('Cache-Control', 'no-store, private');
    }
}
