<?php

declare(strict_types=1);

namespace App\Application\Mcp;

use App\Application\Mcp\Input\McpUpdateDescriptionInput;
use App\Application\Mcp\Output\McpDescriptionUpdatedPayload;
use App\Application\Mcp\Output\McpUntrustedText;
use App\Application\PageCatalog\UpdatePageDescription;
use App\Application\PageCatalog\UpdatePageDescriptionCommand;
use App\Models\Page;
use App\Models\User;

/**
 * Lets an approved AI client describe an existing artifact without granting it
 * authority to silently rewrite the title, hierarchy, owner, or taxonomy.
 */
final readonly class McpUpdateDescriptionTool
{
    public function __construct(
        private McpPageResolver $pages,
        private UpdatePageDescription $updatePageDescription,
        private McpToolErrorMapper $errors,
    ) {
    }

    public function handle(User $actor, McpUpdateDescriptionInput $input): McpToolResult
    {
        $page = $this->pages->editablePage($actor, $input->pageUid);

        if (!$page instanceof Page) {
            return McpToolResult::notFound();
        }

        return $this->errors->guard(function () use ($actor, $input, $page): McpToolResult {
            $updated = $this->updatePageDescription->handle($actor, new UpdatePageDescriptionCommand(
                pageUid: $page->uid,
                expectedCurrentVersionUid: $input->expectedCurrentVersionUid,
                expectedMetadataRevision: $input->expectedMetadataRevision,
                description: $input->description,
            ));

            return McpToolResult::success(new McpDescriptionUpdatedPayload(
                pageUid: $updated->uid,
                currentVersionUid: $updated->current_version_uid,
                metadataRevision: $updated->metadata_revision,
                description: $updated->description === null
                    ? null
                    : new McpUntrustedText($updated->description),
            ));
        });
    }
}
