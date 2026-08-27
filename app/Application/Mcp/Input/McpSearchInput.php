<?php

declare(strict_types=1);

namespace App\Application\Mcp\Input;

use App\Application\Mcp\McpToolArguments;
use App\Application\PageCatalog\PageSearchSort;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\Provenance\ProvenanceSearchScope;

final readonly class McpSearchInput
{
    /**
     * @param list<string> $tagUids
     */
    private function __construct(
        public ?string $query,
        public ?string $workspaceUid,
        public ?PageType $type,
        public ?PageStatus $status,
        public ?string $categoryUid,
        public array $tagUids,
        public ?string $ownerUserUid,
        public ?string $aiProvider,
        public ?string $aiModelQuery,
        public ProvenanceSearchScope $provenanceScope,
        public bool $includeArchived,
        public bool $includeSnippet,
        public PageSearchSort $sort,
    ) {
    }

    public static function fromArguments(McpToolArguments $arguments): self
    {
        $provenanceScope = ProvenanceSearchScope::tryFrom($arguments->string(
            'provenance_scope',
            ProvenanceSearchScope::AnyVersion->value,
        ));

        if (!$provenanceScope instanceof ProvenanceSearchScope) {
            throw new DomainRuleViolation('Argument [provenance_scope] has an unsupported value.');
        }

        $sort = PageSearchSort::tryFrom($arguments->string('sort', PageSearchSort::Relevance->value))
            ?? PageSearchSort::Relevance;

        return new self(
            query: $arguments->nullableString('query'),
            workspaceUid: $arguments->nullableString('workspace_uid'),
            type: $arguments->pageType('type'),
            status: $arguments->pageStatus('status'),
            categoryUid: $arguments->nullableString('category_uid'),
            tagUids: $arguments->stringList('tag_uids'),
            ownerUserUid: $arguments->nullableString('owner_user_uid'),
            aiProvider: $arguments->nullableString('ai_provider'),
            aiModelQuery: $arguments->nullableString('ai_model_query'),
            provenanceScope: $provenanceScope,
            includeArchived: $arguments->bool('include_archived', false),
            includeSnippet: $arguments->bool('include_snippet', false),
            sort: $sort,
        );
    }
}
