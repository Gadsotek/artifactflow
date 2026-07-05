<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\PageEditingPresenceChanged;
use App\Http\Requests\PageCatalog\UpdatePagePresenceRequest;
use App\Models\Page;
use Illuminate\Http\JsonResponse;

final readonly class PagePresenceController
{
    use Concerns\ResolvesAuthenticatedUser;

    public function __invoke(UpdatePagePresenceRequest $request, Page $page): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        broadcast(new PageEditingPresenceChanged(
            pageUid: $page->uid,
            userUid: $user->uid,
            userName: $user->name,
            editing: $request->isEditing(),
        ));

        return response()->json(['ok' => true]);
    }
}
