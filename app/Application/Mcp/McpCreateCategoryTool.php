<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\CreateCategory;
use App\Application\PageCatalog\CreateCategoryCommand;
use App\Models\User;

final readonly class McpCreateCategoryTool
{
    public function __construct(
        private CreateCategory $createCategory,
        private McpToolErrorMapper $errors,
    ) {
    }

    public function handle(User $actor, McpToolArguments $arguments): McpToolResult
    {
        return $this->errors->guard(function () use ($actor, $arguments): McpToolResult {
            $category = $this->createCategory->handle($actor, new CreateCategoryCommand(
                workspaceUid: $arguments->requiredString('workspace_uid'),
                name: $arguments->requiredString('name'),
            ));

            return McpToolResult::success([
                'uid' => $category->uid,
                'workspace_uid' => $category->workspace_uid,
                'name' => McpDataEnvelope::text($category->name),
                'slug' => McpDataEnvelope::text($category->slug),
            ]);
        });
    }
}
