<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\RevertToPreviousVersion;
use App\Application\PageCatalog\RevertToPreviousVersionCommand;
use App\Models\Page;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * MCP revert tool: restore the previous page version through the same
 * RevertToPreviousVersion handler and OCC check as the human UI.
 */
final readonly class McpRevertTool
{
    private const string STALE_CONFLICT_MESSAGE = 'The submitted base_version_uid is stale.';

    public function __construct(
        private McpPageResolver $pages,
        private RevertToPreviousVersion $revertToPreviousVersion,
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
            $result = $this->revertToPreviousVersion->handle($actor, new RevertToPreviousVersionCommand(
                pageUid: $page->uid,
                baseVersionUid: $arguments->requiredString('base_version_uid'),
            ));

            return $this->jsonRpc->toolSuccess($id, [
                'page_uid' => $page->uid,
                'version_uid' => $result->restoredVersion->uid,
                'current_version_uid' => $result->restoredVersion->uid,
                'restored_from_version_uid' => $result->restoredFromVersion->uid,
            ]);
        }, self::STALE_CONFLICT_MESSAGE);
    }
}
