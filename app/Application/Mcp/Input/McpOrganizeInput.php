<?php

declare(strict_types=1);

namespace App\Application\Mcp\Input;

use App\Application\Mcp\McpToolArguments;

final readonly class McpOrganizeInput
{
    /**
     * @param list<string> $tags
     */
    private function __construct(
        public string $pageUid,
        public int $expectedMetadataRevision,
        public bool $titleProvided,
        public ?string $title,
        public bool $parentPageUidProvided,
        public ?string $parentPageUid,
        public bool $categoryUidProvided,
        public ?string $categoryUid,
        public bool $tagsProvided,
        public array $tags,
    ) {
    }

    public static function fromArguments(McpToolArguments $arguments): self
    {
        $titleProvided = $arguments->has('title');
        $tagsProvided = $arguments->has('tags');

        return new self(
            pageUid: $arguments->requiredString('page_uid'),
            expectedMetadataRevision: $arguments->requiredNonNegativeInt('expected_metadata_revision'),
            titleProvided: $titleProvided,
            title: $titleProvided ? $arguments->requiredString('title') : null,
            parentPageUidProvided: $arguments->has('parent_page_uid'),
            parentPageUid: $arguments->nullableString('parent_page_uid'),
            categoryUidProvided: $arguments->has('category_uid'),
            categoryUid: $arguments->nullableString('category_uid'),
            tagsProvided: $tagsProvided,
            tags: $tagsProvided ? $arguments->stringList('tags') : [],
        );
    }

    public function hasMutation(): bool
    {
        return $this->titleProvided
            || $this->parentPageUidProvided
            || $this->categoryUidProvided
            || $this->tagsProvided;
    }
}
