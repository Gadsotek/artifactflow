<?php

declare(strict_types=1);

namespace App\Application\Mcp\Input;

use App\Application\Mcp\McpToolArguments;

final readonly class McpCreateCategoryInput
{
    private function __construct(
        public string $workspaceUid,
        public string $name,
    ) {
    }

    public static function fromArguments(McpToolArguments $arguments): self
    {
        return new self(
            workspaceUid: $arguments->requiredString('workspace_uid'),
            name: $arguments->requiredString('name'),
        );
    }
}
