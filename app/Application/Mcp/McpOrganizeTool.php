<?php

declare(strict_types=1);

namespace App\Application\Mcp;

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
    private const array MUTABLE_ARGUMENTS = [
        'title',
        'parent_page_uid',
        'category_uid',
        'tags',
    ];

    public function __construct(
        private McpPageResolver $pages,
        private UpdatePageMetadata $updatePageMetadata,
        private McpPagePayload $payload,
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
            if (!$this->hasMutation($arguments)) {
                throw new DomainRuleViolation('At least one organizational field is required.');
            }

            $page->loadMissing('tags');
            $tagNames = [];

            foreach ($page->tags as $tag) {
                $tagNames[] = $tag->name;
            }

            $updated = $this->updatePageMetadata->handle($actor, new UpdatePageMetadataCommand(
                pageUid: $page->uid,
                expectedMetadataRevision: $arguments->requiredNonNegativeInt('expected_metadata_revision'),
                title: $arguments->has('title') ? $arguments->requiredString('title') : $page->title,
                description: $page->description,
                categoryUid: $arguments->has('category_uid')
                    ? $arguments->nullableString('category_uid')
                    : $page->category_uid,
                parentPageUid: $arguments->has('parent_page_uid')
                    ? $arguments->nullableString('parent_page_uid')
                    : $page->parent_page_uid,
                ownerUserUid: $page->owner_user_uid,
                tagNames: $arguments->has('tags') ? $arguments->stringList('tags') : $tagNames,
            ));

            return McpToolResult::success($this->payload->forPage($updated) + [
                'current_version_uid' => $updated->current_version_uid,
                'parent_page_uid' => $updated->parent_page_uid,
                'category_uid' => $updated->category_uid,
            ]);
        });
    }

    private function hasMutation(McpToolArguments $arguments): bool
    {
        foreach (self::MUTABLE_ARGUMENTS as $argument) {
            if ($arguments->has($argument)) {
                return true;
            }
        }

        return false;
    }
}
