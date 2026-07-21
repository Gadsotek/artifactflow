<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageVersionSource;
use App\Models\User;

/**
 * MCP create tool: create a page through the same CreatePage handler,
 * policies, scanners, and audit trail as the human UI.
 */
final readonly class McpCreateTool
{
    public function __construct(
        private CreatePage $createPage,
        private McpPagePayload $payload,
        private McpToolErrorMapper $errors,
    ) {
    }

    public function handle(User $actor, McpToolArguments $arguments): McpToolResult
    {
        return $this->errors->guard(function () use ($actor, $arguments): McpToolResult {
            $page = $this->createPage->handle($actor, new CreatePageCommand(
                workspaceUid: $arguments->requiredString('workspace_uid'),
                type: $arguments->requiredPageType('type'),
                title: $arguments->requiredString('title'),
                description: $arguments->nullableString('description'),
                content: $arguments->requiredString('content'),
                status: $arguments->pageStatus('status') ?? PageStatus::Draft,
                categoryUid: $arguments->nullableString('category_uid'),
                tagNames: $arguments->stringList('tags'),
                sourceFilename: $arguments->nullableString('source_filename'),
                source: PageVersionSource::Mcp,
                categoryName: $arguments->nullableString('category_name'),
            ));

            return McpToolResult::success($this->payload->forPage($page) + [
                'current_version_uid' => $page->current_version_uid,
            ]);
        });
    }
}
