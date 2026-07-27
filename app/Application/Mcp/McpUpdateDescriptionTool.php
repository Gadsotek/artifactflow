<?php

declare(strict_types=1);

namespace App\Application\Mcp;

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

    public function handle(User $actor, McpToolArguments $arguments): McpToolResult
    {
        $page = $this->pages->editablePage($actor, $arguments->requiredString('page_uid'));

        if (!$page instanceof Page) {
            return McpToolResult::notFound();
        }

        return $this->errors->guard(function () use ($actor, $arguments, $page): McpToolResult {
            $updated = $this->updatePageDescription->handle($actor, new UpdatePageDescriptionCommand(
                pageUid: $page->uid,
                expectedCurrentVersionUid: $arguments->requiredString('expected_current_version_uid'),
                expectedMetadataRevision: $arguments->requiredNonNegativeInt('expected_metadata_revision'),
                description: $arguments->nullableString('description'),
            ));

            return McpToolResult::success([
                'page_uid' => $updated->uid,
                'current_version_uid' => $updated->current_version_uid,
                'metadata_revision' => $updated->metadata_revision,
                'description' => McpDataEnvelope::text($updated->description),
            ]);
        });
    }
}
