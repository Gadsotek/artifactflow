<?php

declare(strict_types=1);

namespace App\Application\Mcp\Input;

use App\Application\Mcp\McpProvenanceArguments;
use App\Application\Mcp\McpToolArguments;
use App\Application\Provenance\VersionProvenanceInput;
use App\Domain\PageCatalog\PageStatus;

final readonly class McpCreateDocxInput
{
    /** @param list<string> $tags */
    private function __construct(
        public string $workspaceUid,
        public string $title,
        public string $encodedDocx,
        public string $changeSummary,
        public ?string $description,
        public PageStatus $status,
        public ?string $categoryUid,
        public ?string $categoryName,
        public ?string $parentPageUid,
        public array $tags,
        public ?VersionProvenanceInput $provenance,
    ) {
    }

    public static function fromArguments(McpToolArguments $arguments, McpProvenanceArguments $provenance): self
    {
        return new self(
            workspaceUid: $arguments->requiredString('workspace_uid'),
            title: $arguments->requiredString('title'),
            encodedDocx: $arguments->requiredRawString('docx_base64'),
            changeSummary: $arguments->requiredString('change_summary'),
            description: $arguments->nullableString('description'),
            status: $arguments->pageStatus('status') ?? PageStatus::Draft,
            categoryUid: $arguments->nullableString('category_uid'),
            categoryName: $arguments->nullableString('category_name'),
            parentPageUid: $arguments->nullableString('parent_page_uid'),
            tags: $arguments->stringList('tags'),
            provenance: $provenance->fromArguments($arguments),
        );
    }
}
