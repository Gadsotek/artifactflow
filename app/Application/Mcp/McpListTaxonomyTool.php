<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\PageFilterTaxonomy;
use App\Models\Category;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Lists categories in reachable workspaces plus taxonomy attached to pages the
 * token can search. Workspace-scoped tokens are constrained by the same
 * effective-authority context as search.
 */
final readonly class McpListTaxonomyTool
{
    public function __construct(
        private PageFilterTaxonomy $taxonomy,
        private McpJsonRpc $jsonRpc,
    ) {
    }

    public function handle(mixed $id, User $actor, McpToolArguments $arguments): JsonResponse
    {
        $taxonomy = $this->taxonomy->forUser($actor, $arguments->nullableString('workspace_uid'));

        return $this->jsonRpc->toolSuccess($id, [
            'categories' => array_map(static fn (Category $category): array => [
                'uid' => $category->uid,
                'name' => McpDataEnvelope::text($category->name),
                'slug' => McpDataEnvelope::text($category->slug),
                'workspace_uid' => $category->workspace_uid,
                'workspace_name' => McpDataEnvelope::text($category->workspace->name),
            ], $taxonomy->categories),
            'tags' => array_map(static fn (Tag $tag): array => [
                'uid' => $tag->uid,
                'name' => McpDataEnvelope::text($tag->name),
                'slug' => McpDataEnvelope::text($tag->slug),
            ], $taxonomy->tags),
        ]);
    }
}
