<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\CreateCategory;
use App\Application\PageCatalog\CreateCategoryCommand;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final readonly class McpCreateCategoryTool
{
    public function __construct(
        private CreateCategory $createCategory,
        private McpJsonRpc $jsonRpc,
        private McpToolErrorMapper $errors,
    ) {
    }

    public function handle(mixed $id, User $actor, McpToolArguments $arguments): JsonResponse
    {
        return $this->errors->guard($id, function () use ($id, $actor, $arguments): JsonResponse {
            $category = $this->createCategory->handle($actor, new CreateCategoryCommand(
                workspaceUid: $arguments->requiredString('workspace_uid'),
                name: $arguments->requiredString('name'),
            ));

            return $this->jsonRpc->toolSuccess($id, [
                'uid' => $category->uid,
                'workspace_uid' => $category->workspace_uid,
                'name' => McpDataEnvelope::text($category->name),
                'slug' => McpDataEnvelope::text($category->slug),
            ]);
        });
    }
}
