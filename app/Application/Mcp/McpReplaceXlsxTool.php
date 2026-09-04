<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Input\McpReplaceXlsxInput;
use App\Application\Mcp\Output\McpVersionWrittenPayload;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Domain\PageCatalog\StalePageVersionException;
use App\Models\Page;
use App\Models\User;

final readonly class McpReplaceXlsxTool
{
    public function __construct(
        private McpPageResolver $pages,
        private McpXlsxUpload $upload,
        private UpdatePageContent $updatePageContent,
        private McpXlsxVersionPayload $xlsxPayload,
        private McpToolErrorMapper $errors,
        private McpStoredProvenancePayload $storedProvenance,
    ) {
    }

    public function handle(User $actor, McpReplaceXlsxInput $input): McpToolResult
    {
        $page = $this->pages->editablePage($actor, $input->pageUid);

        if (!$page instanceof Page) {
            return McpToolResult::notFound();
        }

        return $this->errors->guard(function () use ($actor, $input, $page): McpToolResult {
            if ($page->type !== PageType::Xlsx) {
                throw new DomainRuleViolation('Only Excel workbook pages can be changed through replace_xlsx.');
            }

            if ($page->current_version_uid !== $input->baseVersionUid) {
                throw new StalePageVersionException(
                    currentVersionUid: (string) $page->current_version_uid,
                    submittedBaseVersionUid: $input->baseVersionUid,
                );
            }

            $version = $this->updatePageContent->handle($actor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: $this->upload->decode($input->encodedXlsx),
                source: PageVersionSource::Mcp,
                baseVersionUid: $input->baseVersionUid,
                provenance: $input->provenance,
                changeSummary: $input->changeSummary,
            ));
            $xlsx = $this->xlsxPayload->facts($version);

            if ($xlsx === null) {
                throw new DomainRuleViolation('XLSX processing facts are unavailable.');
            }

            return McpToolResult::success(new McpVersionWrittenPayload(
                pageUid: $page->uid,
                versionUid: $version->uid,
                currentVersionUid: $version->uid,
                storedProvenance: $this->storedProvenance->forVersion($version),
                xlsx: $xlsx,
            ));
        });
    }
}
