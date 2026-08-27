<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Input\McpOrganizeInput;
use App\Application\Mcp\Output\McpPageOrganizedPayload;
use App\Application\PageCatalog\UpdatePageMetadata;
use App\Application\PageCatalog\UpdatePageMetadataCommand;
use App\Domain\DomainRuleViolation;
use App\Models\Page;
use App\Models\User;

/**
 * MCP organization tool: expose the non-privileged organizational subset of
 * UpdatePageMetadata while preserving every field omitted by the caller.
 */
final readonly class McpOrganizeTool
{
    public function __construct(
        private McpPageResolver $pages,
        private UpdatePageMetadata $updatePageMetadata,
        private McpPagePayload $payload,
        private McpToolErrorMapper $errors,
    ) {
    }

    public function handle(User $actor, McpOrganizeInput $input): McpToolResult
    {
        $page = $this->pages->editablePage($actor, $input->pageUid);

        if (!$page instanceof Page) {
            return McpToolResult::notFound();
        }

        return $this->errors->guard(function () use ($actor, $input, $page): McpToolResult {
            if (!$input->hasMutation()) {
                throw new DomainRuleViolation('At least one organizational field is required.');
            }

            $page->loadMissing('tags');
            $tagNames = [];

            foreach ($page->tags as $tag) {
                $tagNames[] = $tag->name;
            }

            $updated = $this->updatePageMetadata->handle($actor, new UpdatePageMetadataCommand(
                pageUid: $page->uid,
                expectedMetadataRevision: $input->expectedMetadataRevision,
                title: $input->titleProvided ? (string) $input->title : $page->title,
                description: $page->description,
                categoryUid: $input->categoryUidProvided
                    ? $input->categoryUid
                    : $page->category_uid,
                parentPageUid: $input->parentPageUidProvided
                    ? $input->parentPageUid
                    : $page->parent_page_uid,
                ownerUserUid: $page->owner_user_uid,
                tagNames: $input->tagsProvided ? $input->tags : $tagNames,
            ));

            return McpToolResult::success(new McpPageOrganizedPayload(
                page: $this->payload->forPage($updated),
                currentVersionUid: $updated->current_version_uid,
                parentPageUid: $updated->parent_page_uid,
                categoryUid: $updated->category_uid,
            ));
        });
    }
}
