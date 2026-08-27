<?php

declare(strict_types=1);

namespace App\Application\Mcp\Input;

use App\Application\Mcp\McpProvenanceArguments;
use App\Application\Mcp\McpToolArguments;
use App\Application\Provenance\VersionProvenanceInput;

final readonly class McpUpdateContentInput
{
    private function __construct(
        public string $pageUid,
        public string $content,
        public ?string $baseVersionUid,
        public string $changeSummary,
        public ?VersionProvenanceInput $provenance,
    ) {
    }

    public static function fromArguments(
        McpToolArguments $arguments,
        McpProvenanceArguments $provenance,
    ): self {
        return new self(
            pageUid: $arguments->requiredString('page_uid'),
            content: $arguments->requiredString('content'),
            baseVersionUid: $arguments->nullableString('base_version_uid'),
            changeSummary: $arguments->requiredString('change_summary'),
            provenance: $provenance->fromArguments($arguments),
        );
    }
}
