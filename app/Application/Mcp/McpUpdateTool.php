<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\PageCatalog\PageVersionSource;
use App\Models\Page;
use App\Models\User;

/**
 * MCP update tool: append a page version through the same UpdatePageContent
 * handler and optimistic-concurrency check as the human UI.
 */
final readonly class McpUpdateTool
{
    public function __construct(
        private McpPageResolver $pages,
        private UpdatePageContent $updatePageContent,
        private McpToolErrorMapper $errors,
    ) {
    }

    public function handle(User $actor, McpToolArguments $arguments): McpToolResult
    {
        $page = $this->pages->editablePage($actor, $arguments->requiredString('page_uid'));

        if (!$page instanceof Page) {
            return McpToolResult::notFound();
        }

        return $this->errors->guard(function () use ($actor, $arguments, $page): McpToolResult {
            $version = $this->updatePageContent->handle($actor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: $arguments->requiredString('content'),
                source: PageVersionSource::Mcp,
                baseVersionUid: $arguments->nullableString('base_version_uid'),
            ));

            return McpToolResult::success([
                'page_uid' => $page->uid,
                'version_uid' => $version->uid,
                'current_version_uid' => $version->uid,
            ]);
        });
    }
}
