<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpSearchPayload implements McpWirePayload
{
    /**
     * @param list<McpSearchResultView> $results
     */
    public function __construct(public array $results)
    {
    }

    /**
     * @return array{results: list<array<string, mixed>>}
     */
    public function toWire(): array
    {
        return [
            'results' => array_map(
                static fn (McpSearchResultView $result): array => $result->toWire(),
                $this->results,
            ),
        ];
    }
}
