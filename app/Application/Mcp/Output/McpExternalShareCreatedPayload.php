<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;

final readonly class McpExternalShareCreatedPayload implements McpWirePayload
{
    public function __construct(
        public string $shareUid,
        public string $pageUid,
        public string $mode,
        public ?string $expiresAt,
        public string $createdAt,
        public string $url,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'share_uid' => $this->shareUid,
            'page_uid' => $this->pageUid,
            'mode' => $this->mode,
            'expires_at' => $this->expiresAt,
            'created_at' => $this->createdAt,
            'url' => $this->url,
            'secret_presented_once' => true,
        ];
    }
}
