<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\CreateTag;
use App\Application\PageCatalog\CreateTagCommand;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final readonly class McpCreateTagTool
{
    public function __construct(
        private CreateTag $createTag,
        private McpJsonRpc $jsonRpc,
        private McpToolErrorMapper $errors,
    ) {
    }

    public function handle(mixed $id, User $actor, McpToolArguments $arguments): JsonResponse
    {
        return $this->errors->guard($id, function () use ($id, $actor, $arguments): JsonResponse {
            $workspaceUid = $arguments->requiredString('workspace_uid');
            $tag = $this->createTag->handle($actor, new CreateTagCommand(
                workspaceUid: $workspaceUid,
                name: $arguments->requiredString('name'),
            ));

            return $this->jsonRpc->toolSuccess($id, [
                'uid' => $tag->uid,
                'workspace_uid' => $workspaceUid,
                'name' => McpDataEnvelope::text($tag->name),
                'slug' => McpDataEnvelope::text($tag->slug),
            ]);
        });
    }
}
