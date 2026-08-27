<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Input\McpReplaceImageInput;
use App\Application\Mcp\Output\McpVersionWrittenPayload;
use App\Application\PageCatalog\UpdatePageContent;
use App\Application\PageCatalog\UpdatePageContentCommand;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;
use App\Domain\PageCatalog\PageVersionSource;
use App\Models\Page;
use App\Models\User;

final readonly class McpReplaceImageTool
{
    public function __construct(
        private McpPageResolver $pages,
        private McpImageUpload $upload,
        private UpdatePageContent $updatePageContent,
        private McpToolErrorMapper $errors,
        private McpStoredProvenancePayload $storedProvenance,
    ) {
    }

    public function handle(User $actor, McpReplaceImageInput $input): McpToolResult
    {
        $page = $this->pages->editablePage($actor, $input->pageUid);

        if (!$page instanceof Page) {
            return McpToolResult::notFound();
        }

        return $this->errors->guard(function () use ($actor, $input, $page): McpToolResult {
            if ($page->type !== PageType::Image) {
                throw new DomainRuleViolation('Only image pages can be changed through replace_image.');
            }

            $version = $this->updatePageContent->handle($actor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: $this->upload->decode($input->encodedImage, $input->mediaType),
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
