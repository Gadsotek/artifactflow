<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\RevertToPreviousVersion;
use App\Application\PageCatalog\RevertToPreviousVersionCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;
use App\Models\Page;
use App\Models\User;

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
            if ($page->type === PageType::Image) {
                throw new DomainRuleViolation(
                    'Image content must be replaced through an authenticated PNG/JPEG upload.',
                );
            }

            $result = $this->revertToPreviousVersion->handle($actor, new RevertToPreviousVersionCommand(
                pageUid: $page->uid,
                baseVersionUid: $arguments->requiredString('base_version_uid'),
            ));

            return McpToolResult::success([
                'page_uid' => $page->uid,
                'version_uid' => $result->restoredVersion->uid,
                'current_version_uid' => $result->restoredVersion->uid,
                'restored_from_version_uid' => $result->restoredFromVersion->uid,
            ]);
        }, self::STALE_CONFLICT_MESSAGE);
    }
}
