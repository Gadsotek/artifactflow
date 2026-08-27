<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Input\McpCreateTagInput;
use App\Application\Mcp\Output\McpTagCreatedPayload;
use App\Application\Mcp\Output\McpTagView;
use App\Application\Mcp\Output\McpUntrustedText;
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

    public function handle(User $actor, McpCreateTagInput $input): McpToolResult
    {
        return $this->errors->guard(function () use ($actor, $input): McpToolResult {
            $workspaceUid = $input->workspaceUid;
            $tag = $this->createTag->handle($actor, new CreateTagCommand(
                workspaceUid: $workspaceUid,
                name: $input->name,
            ));

            return McpToolResult::success(new McpTagCreatedPayload(
                tag: new McpTagView(
                    uid: $tag->uid,
                    name: new McpUntrustedText($tag->name),
                    slug: new McpUntrustedText($tag->slug),
                ),
                authorityWorkspaceUid: $workspaceUid,
            ));
        }, authorizationResource: McpNotFoundResource::Workspace);
    }
}
