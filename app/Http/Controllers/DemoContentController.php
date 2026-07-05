<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\PageCatalog\SeedDemoContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class DemoContentController
{
    use Concerns\ResolvesAuthenticatedUser;

    public function __invoke(Request $request, SeedDemoContent $seedDemoContent): RedirectResponse
    {
        $user = $this->authenticatedUser($request);

        $pages = $seedDemoContent->handle($user);
        $workspaceUid = $pages[0]->workspace_uid ?? null;

        if (is_string($workspaceUid)) {
            $request->session()->put('current_workspace_uid', $workspaceUid);
        }

        return redirect()
            ->route('dashboard')
            ->with('status', 'Hello World examples are ready in your personal workspace.');
    }
}
