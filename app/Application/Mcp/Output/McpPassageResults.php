<?php

declare(strict_types=1);

namespace App\Application\Mcp\Output;

use App\Application\Mcp\McpWirePayload;
use App\Models\IndexedPassage;
use App\Models\Page;
use App\Models\PageVersion;
use LogicException;

final class McpPassageResults implements McpWirePayload
{
    private const int MAX_BYTES = 65_536;
    /** @var list<array<string, mixed>> */
    private array $results = [];
    private int $bytes = 14; // {"results":[]}

    public function tryAppend(Page $page, PageVersion $version, IndexedPassage $passage, bool $limited): bool
    {
        $appUrl = config('app.url');
        if (!is_string($appUrl) || $appUrl === '') {
            throw new LogicException('Passage citations require an application URL.');
        }
        $hit = [
            'page_uid' => $page->uid,
            'version_uid' => $version->uid,
            'version_number' => $version->version_number,
            'type' => $page->type->value,
            'status' => $page->status->value,
            'title' => (new McpUntrustedText($page->title))->toWire(),
            'heading_path' => array_map(static fn (string $heading): array => (new McpUntrustedText($heading))->toWire(), $passage->heading_path),
            'excerpt' => (new McpUntrustedText($passage->excerpt))->toWire(),
            'source_locator' => $passage->source_locator,
            'citation_url' => rtrim($appUrl, '/') . route('pages.versions.show', ['page' => $page->uid, 'version' => $version->uid], absolute: false),
            'index_limited' => $limited,
        ];
        $bytes = strlen(json_encode($hit, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            + ($this->results === [] ? 0 : 1);
        if ($this->bytes + $bytes > self::MAX_BYTES) {
            return false;
        }
        $this->results[] = $hit;
        $this->bytes += $bytes;

        return true;
    }

    public function count(): int
    {
        return count($this->results);
    }

    /** @return array{results: list<array<string, mixed>>} */
    public function toWire(): array
    {
        return ['results' => $this->results];
    }
}
