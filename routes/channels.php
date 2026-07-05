<?php

declare(strict_types=1);

use App\Application\PageCatalog\PageAccess;
use App\Models\Page;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('page.{pageUid}', static function (User $user, string $pageUid): array|false {
    $page = Page::query()->find($pageUid);

    if (!$page instanceof Page || !app(PageAccess::class)->canView($user, $page)) {
        return false;
    }

    // Presence payloads are intentionally limited to identity metadata. Page
    // content must always be fetched over the normal authorized HTTP path.
    return [
        'uid' => $user->uid,
        'name' => $user->name,
    ];
});
