<?php

declare(strict_types=1);

namespace App\Application\Mcp\Input;

use App\Application\Mcp\McpToolArguments;

final readonly class McpUpdateDescriptionInput
{
    private function __construct(
        public string $pageUid,
        public string $expectedCurrentVersionUid,
        public int $expectedMetadataRevision,
        public ?string $description,
    ) {
    }

    public static function fromArguments(McpToolArguments $arguments): self
    {
        return new self(
            pageUid: $arguments->requiredString('page_uid'),
            expectedCurrentVersionUid: $arguments->requiredString('expected_current_version_uid'),
            expectedMetadataRevision: $arguments->requiredNonNegativeInt('expected_metadata_revision'),
            description: $arguments->nullableString('description'),
        );
    }
}
