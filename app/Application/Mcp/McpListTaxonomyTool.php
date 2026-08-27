<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Input\McpListTaxonomyInput;
use App\Application\Mcp\Output\McpCategoryView;
use App\Application\Mcp\Output\McpTagView;
use App\Application\Mcp\Output\McpTaxonomyPayload;
use App\Application\Mcp\Output\McpUntrustedText;
use App\Application\PageCatalog\PageFilterTaxonomy;
use App\Models\Category;
use App\Models\Tag;
use App\Models\User;

/**
 * Lists categories in reachable workspaces plus taxonomy attached to pages the
 * token can search. Workspace-scoped tokens are constrained by the same
 * effective-authority context as search.
 */
final readonly class McpListTaxonomyTool
{
    public function __construct(
        private PageFilterTaxonomy $taxonomy,
    ) {
    }

    public function handle(User $actor, McpListTaxonomyInput $input): McpToolResult
    {
        $taxonomy = $this->taxonomy->forUser($actor, $input->workspaceUid);

        return McpToolResult::success(new McpTaxonomyPayload(
            categories: array_map(static fn (Category $category): McpCategoryView => new McpCategoryView(
                uid: $category->uid,
                name: new McpUntrustedText($category->name),
                slug: new McpUntrustedText($category->slug),
                workspaceUid: $category->workspace_uid,
                workspaceName: new McpUntrustedText($category->workspace->name),
            ), $taxonomy->categories),
            tags: array_map(static fn (Tag $tag): McpTagView => new McpTagView(
                uid: $tag->uid,
                name: new McpUntrustedText($tag->name),
                slug: new McpUntrustedText($tag->slug),
            ), $taxonomy->tags),
        ));
    }
}
