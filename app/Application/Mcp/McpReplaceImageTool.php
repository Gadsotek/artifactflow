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

final readonly class McpReplaceImageTool
{
    public function __construct(
        private McpPageResolver $pages,
        private McpImageUpload $upload,
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
            if ($page->type !== PageType::Image) {
                throw new DomainRuleViolation('Only image pages can be changed through replace_image.');
            }

            $declaredProvenance = $this->provenance->fromArguments($arguments);
            $version = $this->updatePageContent->handle($actor, new UpdatePageContentCommand(
                pageUid: $page->uid,
                content: $this->upload->decode($arguments),
                source: PageVersionSource::Mcp,
                baseVersionUid: $arguments->requiredString('base_version_uid'),
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
