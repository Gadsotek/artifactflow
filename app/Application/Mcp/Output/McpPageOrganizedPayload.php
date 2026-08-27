<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpPageOrganizedPayload implements McpWirePayload
{
    public function __construct(
        public McpPageView $page,
        public ?string $currentVersionUid,
        public ?string $parentPageUid,
        public ?string $categoryUid,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        $payload = $this->page->toWire();
        $payload['current_version_uid'] = $this->currentVersionUid;
        $payload['parent_page_uid'] = $this->parentPageUid;
        $payload['category_uid'] = $this->categoryUid;

        return $payload;
    }
}
