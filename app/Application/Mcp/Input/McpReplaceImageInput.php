<?php

declare(strict_types=1);

namespace App\Application\Mcp\Input;

use App\Application\Mcp\McpProvenanceArguments;
use App\Application\Mcp\McpToolArguments;
use App\Application\Provenance\VersionProvenanceInput;

final readonly class McpReplaceImageInput
{
    private function __construct(
        public string $pageUid,
        public string $baseVersionUid,
        public string $encodedImage,
        public string $mediaType,
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
            baseVersionUid: $arguments->requiredString('base_version_uid'),
            encodedImage: $arguments->requiredRawString('image_base64'),
            mediaType: $arguments->requiredString('media_type'),
            changeSummary: $arguments->requiredString('change_summary'),
            provenance: $provenance->fromArguments($arguments),
        );
    }
}
