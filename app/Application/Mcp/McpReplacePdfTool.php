<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Domain\PageCatalog\StalePageVersionException;
use App\Models\Page;
use App\Models\User;

final readonly class McpReplacePdfTool
{
    public function __construct(
        private McpPageResolver $pages,
        private McpPdfUpload $upload,
        private UpdatePageContent $updatePageContent,
        private McpPdfVersionPayload $pdfPayload,
        private McpToolErrorMapper $errors,
        private McpProvenanceArguments $provenance,
        private McpStoredProvenancePayload $storedProvenance,
    ) {
    }

    public function handle(User $actor, McpToolArguments $arguments): McpToolResult
    {
        // Resolve exact page edit authority before decoding document bytes.
        $page = $this->pages->editablePage($actor, $arguments->requiredString('page_uid'));

        if (!$page instanceof Page) {
            return McpToolResult::notFound();
        }

        return $this->errors->guard(function () use ($actor, $arguments, $page): McpToolResult {
            if ($page->type !== PageType::Pdf) {
                throw new DomainRuleViolation('Only PDF pages can be changed through replace_pdf.');
            }

            $baseVersionUid = $arguments->requiredString('base_version_uid');

            // Fast-fail an already stale caller before allocating the decoded
            // document or spending the single native processor slot. The page
            // append repeats this check under its row lock for race safety.
            if ($page->current_version_uid !== $baseVersionUid) {
                throw new StalePageVersionException(
                    currentVersionUid: (string) $page->current_version_uid,
                    submittedBaseVersionUid: $baseVersionUid,
                );
            }

            $declaredProvenance = $this->provenance->fromArguments($arguments);
            $version = $this->updatePageContent->handle($actor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: $this->upload->decode($arguments),
                source: PageVersionSource::Mcp,
                baseVersionUid: $baseVersionUid,
                provenance: $declaredProvenance,
                changeSummary: $arguments->requiredString('change_summary'),
            ));
            $pdf = $this->pdfPayload->forVersion($version);

            if ($pdf === null) {
                throw new DomainRuleViolation('PDF processing facts are unavailable.');
            }

            return McpToolResult::success([
                'page_uid' => $page->uid,
                'version_uid' => $version->uid,
                'current_version_uid' => $version->uid,
                'pdf' => $pdf,
            ] + $this->storedProvenance->forVersion($version));
        });
    }
}
