<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\CreateTag;
use App\Application\PageCatalog\CreateTagCommand;
use App\Models\User;

final readonly class McpCreateTagTool
{
    public function __construct(
        private CreateTag $createTag,
        private McpToolErrorMapper $errors,
    ) {
    }

    public function handle(User $actor, McpToolArguments $arguments): McpToolResult
    {
        return $this->errors->guard(function () use ($actor, $arguments): McpToolResult {
            $workspaceUid = $arguments->requiredString('workspace_uid');
            $tag = $this->createTag->handle($actor, new CreateTagCommand(
                workspaceUid: $workspaceUid,
                name: $arguments->requiredString('name'),
            ));

            return McpToolResult::success([
                'uid' => $tag->uid,
                'workspace_uid' => $workspaceUid,
                'name' => McpDataEnvelope::text($tag->name),
                'slug' => McpDataEnvelope::text($tag->slug),
            ]);
        });
    }
}
