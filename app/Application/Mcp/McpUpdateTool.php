<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\PageCatalog\PageVersionSource;
use App\Models\Page;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * MCP update tool: append a page version through the same UpdatePageContent
 * handler and optimistic-concurrency check as the human UI.
 */
final readonly class McpUpdateTool
{
    public function __construct(
        private McpPageResolver $pages,
        private UpdatePageContent $updatePageContent,
        private McpJsonRpc $jsonRpc,
        private McpToolErrorMapper $errors,
    ) {
    }

    public function handle(mixed $id, User $actor, McpToolArguments $arguments): JsonResponse
    {
        $page = $this->pages->editablePage($actor, $arguments->requiredString('page_uid'));

        if (!$page instanceof Page) {
            return $this->jsonRpc->notFound($id);
        }

        return $this->errors->guard($id, function () use ($id, $actor, $arguments, $page): JsonResponse {
            $version = $this->updatePageContent->handle($actor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: $arguments->requiredString('content'),
                source: PageVersionSource::Mcp,
                baseVersionUid: $arguments->nullableString('base_version_uid'),
            ));

            return $this->jsonRpc->toolSuccess($id, [
                'page_uid' => $page->uid,
                'version_uid' => $version->uid,
                'current_version_uid' => $version->uid,
            ]);
        });
    }
}
