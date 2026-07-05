<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\PageAccess;
use App\Models\Page;
use App\Models\User;

/**
 * Resolves a page for an MCP tool call only when the actor's effective
 * authority can view or edit it. Inaccessible and missing pages both resolve
 * to null so tools answer with the same not_found envelope and never leak
 * page existence.
 */
final readonly class McpPageResolver
{
    public function __construct(
        private PageAccess $pageAccess,
    ) {
    }

    public function viewablePage(User $actor, string $pageUid): ?Page
    {
        $page = Page::query()
            ->with(['currentVersion', 'tags'])
            ->find($pageUid);

        if (!$page instanceof Page) {
            return null;
        }

        return $this->pageAccess->canView($actor, $page) ? $page : null;
    }

    public function editablePage(User $actor, string $pageUid): ?Page
    {
        $page = Page::query()
            ->find($pageUid);

        if (!$page instanceof Page) {
            return null;
        }

        return $this->pageAccess->canEdit($actor, $page) ? $page : null;
    }
}
