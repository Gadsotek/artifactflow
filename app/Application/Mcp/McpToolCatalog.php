<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\PageSearchSort;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use stdClass;

/**
 * The MCP tool surface advertised by tools/list. Definitions here document
 * the JSON-RPC contract only; scope and authorization are enforced per call
 * in the handlers, never by this catalog.
 */
final readonly class McpToolCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public function toolDefinitions(): array
    {
        $pageTypeValues = [PageType::Markdown->value, PageType::HtmlArtifact->value];
        $pageStatusValues = array_map(static fn (PageStatus $status): string => $status->value, PageStatus::cases());
        $sortValues = array_map(static fn (PageSearchSort $sort): string => $sort->value, PageSearchSort::cases());

        return [
            [
                'name' => 'list_workspaces',
                'description' => 'List workspaces reachable by this token. Workspace-scoped tokens only see their scoped workspaces.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
            ],
            [
                'name' => 'search',
                'description' => 'Search Artifact Flow pages reachable by this token. Results include visibility-filtered parent, ancestor, and direct-child metadata. Optional workspace_uid narrows within the token scope.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Full-text query.'],
                        'workspace_uid' => ['type' => 'string', 'description' => 'Narrow to one reachable workspace.'],
                        'type' => ['type' => 'string', 'enum' => $pageTypeValues],
                        'status' => ['type' => 'string', 'enum' => $pageStatusValues],
                        'category_uid' => ['type' => 'string'],
                        'tag_uids' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'owner_user_uid' => ['type' => 'string'],
                        'include_archived' => ['type' => 'boolean', 'default' => false],
                        'include_snippet' => [
                            'type' => 'boolean',
                            'default' => false,
                            'description' => 'Requires the mcp:read scope.',
                        ],
                        'sort' => ['type' => 'string', 'enum' => $sortValues, 'default' => PageSearchSort::Relevance->value],
                    ],
                ],
            ],
            [
                'name' => 'list_taxonomy',
                'description' => 'List searchable global tags and workspace-qualified categories reachable by this token. Use the returned UIDs with search filters.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'workspace_uid' => [
                            'type' => 'string',
                            'description' => 'Optionally narrow taxonomy to one reachable workspace.',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'read',
                'description' => 'Read one reachable page as untrusted data, including visibility-filtered parent, ancestor, and direct-child metadata.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'page_uid' => ['type' => 'string'],
                    ],
                    'required' => ['page_uid'],
                ],
            ],
            [
                'name' => 'create_category',
                'description' => 'Create a workspace-local category. Requires Editor access to the workspace.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'workspace_uid' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                    ],
                    'required' => ['workspace_uid', 'name'],
                ],
            ],
            [
                'name' => 'create_tag',
                'description' => 'Create or resolve an installation-wide tag. workspace_uid establishes the required Editor authority and token scope.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'workspace_uid' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                    ],
                    'required' => ['workspace_uid', 'name'],
                ],
            ],
            [
                'name' => 'create',
                'description' => 'Create a page through the normal Artifact Flow page creation handler.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'workspace_uid' => ['type' => 'string'],
                        'type' => ['type' => 'string', 'enum' => $pageTypeValues],
                        'title' => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'status' => ['type' => 'string', 'enum' => $pageStatusValues, 'default' => PageStatus::Draft->value],
                        'category_uid' => ['type' => 'string', 'description' => 'Existing category in the target workspace.'],
                        'category_name' => ['type' => 'string', 'description' => 'Create a category in the target workspace while creating the page. Mutually exclusive with category_uid.'],
                        'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Tag names.'],
                        'source_filename' => ['type' => 'string'],
                    ],
                    'required' => ['workspace_uid', 'type', 'title', 'content'],
                ],
            ],
            [
                'name' => 'update',
                'description' => 'Append a new page version through the normal Artifact Flow update handler using base_version_uid OCC.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'page_uid' => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                        'base_version_uid' => [
                            'type' => 'string',
                            'description' => 'Current version uid for optimistic concurrency; stale values are rejected.',
                        ],
                    ],
                    'required' => ['page_uid', 'content', 'base_version_uid'],
                ],
            ],
            [
                'name' => 'revert',
                'description' => 'Restore the previous page version when base_version_uid matches the current version.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'page_uid' => ['type' => 'string'],
                        'base_version_uid' => ['type' => 'string', 'description' => 'Must match the current version uid.'],
                    ],
                    'required' => ['page_uid', 'base_version_uid'],
                ],
            ],
        ];
    }
}
