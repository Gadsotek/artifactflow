<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Domain\PageCatalog\PageAccessSubjectType;
use App\Models\Page;
use App\Models\PageAccessGrant;

final class PageAccessRevision
{
    public function bump(Page $page): void
    {
        Page::query()
            ->whereKey($page->uid)
            ->increment('preview_access_revision');
    }

    public function bumpWorkspace(string $workspaceUid): int
    {
        return Page::query()
            ->where('workspace_uid', $workspaceUid)
            ->increment('preview_access_revision');
    }

    public function bumpPagesGrantedToWorkspace(string $workspaceUid): int
    {
        return Page::query()
            ->where('workspace_uid', '<>', $workspaceUid)
            ->whereIn('uid', PageAccessGrant::query()
                ->select('page_uid')
                ->where('subject_type', PageAccessSubjectType::Workspace)
                ->where('subject_uid', $workspaceUid))
            ->increment('preview_access_revision');
    }
}
