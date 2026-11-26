<?php

declare(strict_types=1);

namespace App\Application\Mcp\Input;

use App\Application\Mcp\McpToolArguments;
use App\Application\PageCatalog\PageSearchFilters;
use App\Application\PageCatalog\PageSearchSort;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageType;
use Illuminate\Support\Str;

final readonly class McpPassageSearchInput
{
    public const int MAX_TAGS = 50;

    private function __construct(
        public string $query,
        public int $limit,
        public ?string $pageUid,
        public PageSearchFilters $filters,
    ) {
    }

    public static function fromArguments(McpToolArguments $args): self
    {
        $query = $args->requiredString('query');
        if (mb_strlen($query) > 200 || preg_match('/\A\s*\z/u', $query) === 1 || str_contains($query, "\0")) {
            throw new DomainRuleViolation('Query must contain 1 to 200 searchable characters.');
        }
        $limit = $args->has('limit') ? $args->requiredNonNegativeInt('limit') : 5;
        if ($limit < 1 || $limit > 10) {
            throw new DomainRuleViolation('Limit must be between 1 and 10.');
        }

        $type = $args->has('type') ? $args->requiredPageType('type') : null;
        if ($type !== null && !in_array($type, [PageType::Markdown, PageType::HtmlArtifact], true)) {
            throw new DomainRuleViolation('Passage search supports Markdown and static HTML.');
        }

        $uids = [];
        foreach (['workspace_uid', 'page_uid', 'category_uid', 'owner_user_uid'] as $field) {
            $uid = $args->has($field) ? $args->requiredString($field) : null;
            if ($uid !== null && !Str::isUlid($uid)) {
                throw new DomainRuleViolation(sprintf('Argument [%s] must be a ULID.', $field));
            }
            $uids[$field] = $uid;
        }
        $tagUids = $args->boundedUidList('tag_uids', self::MAX_TAGS);
        $status = $args->pageStatus('status');
        if ($args->has('status') && $status === null) {
            throw new DomainRuleViolation('Argument [status] must be a page status.');
        }

        return new self($query, $limit, $uids['page_uid'], new PageSearchFilters(
            query: $query,
            workspaceUid: $uids['workspace_uid'],
            type: $type,
            statuses: $status === null ? PageSearchFilters::activeStatuses() : [$status],
            categoryUids: $uids['category_uid'] === null ? [] : [$uids['category_uid']],
            tagUids: $tagUids,
            ownerUserUid: $uids['owner_user_uid'],
            sort: PageSearchSort::Relevance,
        ));
    }
}
