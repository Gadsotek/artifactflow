<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Input\McpCreateCategoryInput;
use App\Application\Mcp\Output\McpCategoryCreatedPayload;
use App\Application\Mcp\Output\McpCategoryView;
use App\Application\Mcp\Output\McpUntrustedText;
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

    public function handle(User $actor, McpCreateCategoryInput $input): McpToolResult
    {
        return $this->errors->guard(function () use ($actor, $input): McpToolResult {
            $category = $this->createCategory->handle($actor, new CreateCategoryCommand(
                workspaceUid: $input->workspaceUid,
                name: $input->name,
            ));

            return McpToolResult::success(new McpCategoryCreatedPayload(new McpCategoryView(
                uid: $category->uid,
                name: new McpUntrustedText($category->name),
                slug: new McpUntrustedText($category->slug),
                workspaceUid: $category->workspace_uid,
            )));
        }, authorizationResource: McpNotFoundResource::Workspace);
    }
}
