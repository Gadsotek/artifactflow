<?php

declare(strict_types=1);

namespace App\Application\Mcp\Input;

use App\Application\Mcp\McpToolArguments;

final readonly class McpListTaxonomyInput
{
    private function __construct(public ?string $workspaceUid)
    {
    }

    public static function fromArguments(McpToolArguments $arguments): self
    {
        return new self($arguments->nullableString('workspace_uid'));
    }
}
