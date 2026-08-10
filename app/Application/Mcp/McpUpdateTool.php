<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;
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
        private McpProvenanceArguments $provenance,
        private McpStoredProvenancePayload $storedProvenance,
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
                throw new DomainRuleViolation('Image content must be replaced through an authenticated PNG/JPEG upload.');
            }

            $declaredProvenance = $this->provenance->fromArguments($arguments);
            $version = $this->updatePageContent->handle($actor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: $arguments->requiredString('content'),
                source: PageVersionSource::Mcp,
                baseVersionUid: $arguments->nullableString('base_version_uid'),
                provenance: $declaredProvenance,
                changeSummary: $arguments->requiredString('change_summary'),
            ));

            return McpToolResult::success([
                'page_uid' => $page->uid,
                'version_uid' => $version->uid,
                'current_version_uid' => $version->uid,
            ] + $this->storedProvenance->forVersion($version));
        });
    }
}
