<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Input\McpReplaceDocxInput;
use App\Application\Mcp\Output\McpVersionWrittenPayload;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Domain\PageCatalog\StalePageVersionException;
use App\Models\Page;
use App\Models\User;

final readonly class McpReplaceDocxTool
{
    public function __construct(
        private McpPageResolver $pages,
        private McpDocxUpload $upload,
        private UpdatePageContent $updatePageContent,
        private McpDocxVersionPayload $docxPayload,
        private McpToolErrorMapper $errors,
        private McpStoredProvenancePayload $storedProvenance,
    ) {
    }

    public function handle(User $actor, McpReplaceDocxInput $input): McpToolResult
    {
        $page = $this->pages->editablePage($actor, $input->pageUid);
        if (!$page instanceof Page) {
            return McpToolResult::notFound();
        }

        return $this->errors->guard(function () use ($actor, $input, $page): McpToolResult {
            if ($page->type !== PageType::Docx) {
                throw new DomainRuleViolation('Only Word document pages can be changed through replace_docx.');
            }
            if ($page->current_version_uid !== $input->baseVersionUid) {
                throw new StalePageVersionException((string) $page->current_version_uid, $input->baseVersionUid);
            }
            $version = $this->updatePageContent->handle($actor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: $this->upload->decode($input->encodedDocx),
                source: PageVersionSource::Mcp,
                baseVersionUid: $input->baseVersionUid,
                provenance: $input->provenance,
                changeSummary: $input->changeSummary,
            ));
            $docx = $this->docxPayload->facts($version);
            if ($docx === null) {
                throw new DomainRuleViolation('DOCX processing facts are unavailable.');
            }

            return McpToolResult::success(new McpVersionWrittenPayload(
                pageUid: $page->uid,
                versionUid: $version->uid,
                currentVersionUid: $version->uid,
                storedProvenance: $this->storedProvenance->forVersion($version),
                docx: $docx,
            ));
        });
    }
}
