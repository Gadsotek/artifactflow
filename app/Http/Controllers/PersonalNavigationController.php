<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PageCatalog\PersonalNavigation;
use App\Application\PageCatalog\PersonalPageState;
use App\Http\Requests\PageCatalog\QuickNavigationRequest;
use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final readonly class PersonalNavigationController
{
    use Concerns\ResolvesAuthenticatedUser;

    public function __construct(private PersonalNavigation $navigation, private PersonalPageState $state)
    {
    }

    public function recent(Request $request): View
    {
        return view('pages.personal', [
            'title' => 'Recently opened',
            'collection' => 'recent',
            'pages' => $this->navigation->pages($this->authenticatedUser($request), 'recent'),
        ]);
    }

    public function favorites(Request $request): View
    {
        return view('pages.personal', [
            'title' => 'Favorites',
            'collection' => 'favorites',
            'pages' => $this->navigation->pages($this->authenticatedUser($request), 'favorites'),
        ]);
    }

    public function search(QuickNavigationRequest $request): JsonResponse
    {
        return response()->json(['pages' => $this->navigation->pages(
            $this->authenticatedUser($request),
            'quick',
            trim($request->string('q')->toString()),
            $request->filled('workspace_uid') ? $request->string('workspace_uid')->toString() : null,
        )])->header('Cache-Control', 'private, no-store');
    }

    public function favorite(Request $request, Page $page): JsonResponse|RedirectResponse
    {
        $favorite = $request->isMethod('PUT');
        $this->state->favorite($this->authenticatedUser($request), $page, $favorite);

        return $request->expectsJson()
            ? response()->json(['favorite' => $favorite])
            : redirect()->back();
    }

    public function clearRecent(Request $request): RedirectResponse
    {
        $this->state->clearRecent($this->authenticatedUser($request));

        return redirect()->route('navigation.recent')->with('status', 'Recently opened history cleared.');
    }
}
