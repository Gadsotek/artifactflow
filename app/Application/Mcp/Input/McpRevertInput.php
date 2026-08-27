<?php

declare(strict_types=1);

namespace App\Application\Mcp\Input;

use App\Application\Mcp\McpToolArguments;

final readonly class McpRevertInput
{
    private function __construct(
        public string $pageUid,
        public string $baseVersionUid,
        public string $changeSummary,
    ) {
    }

    public static function fromArguments(McpToolArguments $arguments): self
    {
        return new self(
            pageUid: $arguments->requiredString('page_uid'),
            baseVersionUid: $arguments->requiredString('base_version_uid'),
            changeSummary: $arguments->requiredString('change_summary'),
        );
    }
}
