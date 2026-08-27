<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpWorkspaceListPayload implements McpWirePayload
{
    /**
     * @param list<McpWorkspaceView> $workspaces
     */
    public function __construct(public array $workspaces)
    {
    }

    /**
     * @return array{workspaces: list<array<string, mixed>>}
     */
    public function toWire(): array
    {
        return [
            'workspaces' => array_map(
                static fn (McpWorkspaceView $workspace): array => $workspace->toWire(),
                $this->workspaces,
            ),
        ];
    }
}
