<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Input\McpUpdateContentInput;
use App\Application\Mcp\Output\McpVersionWrittenPayload;
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
        private McpStoredProvenancePayload $storedProvenance,
    ) {
    }

    public function handle(User $actor, McpUpdateContentInput $input): McpToolResult
    {
        $page = $this->pages->editablePage($actor, $input->pageUid);

        if (!$page instanceof Page) {
            return McpToolResult::notFound();
        }

        return $this->errors->guard(function () use ($actor, $input, $page): McpToolResult {
            if (in_array($page->type, [PageType::Image, PageType::Pdf, PageType::Xlsx, PageType::Docx], true)) {
                throw new DomainRuleViolation('Binary content must be replaced through a dedicated authenticated upload.');
            }

            $version = $this->updatePageContent->handle($actor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: $input->content,
                source: PageVersionSource::Mcp,
                baseVersionUid: $input->baseVersionUid,
                provenance: $input->provenance,
                changeSummary: $input->changeSummary,
            ));

            return McpToolResult::success(new McpVersionWrittenPayload(
                pageUid: $page->uid,
                versionUid: $version->uid,
                currentVersionUid: $version->uid,
                storedProvenance: $this->storedProvenance->forVersion($version),
            ));
        });
    }
}
