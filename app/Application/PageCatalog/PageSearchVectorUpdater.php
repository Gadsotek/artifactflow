<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use Illuminate\Support\Facades\DB;

final class PageSearchVectorUpdater
{
    public const string TEXT_SEARCH_CONFIG = 'simple';
    public const int MAX_EXTRACTED_TEXT_SEARCH_CHARACTERS = 200000;
    public const int MAX_PROVENANCE_SEARCH_ENTRIES = 256;

    public function refreshPage(string $pageUid): void
    {
        DB::update($this->refreshPageSql(), [$pageUid]);
    }

    public function refreshWorkspace(string $workspaceUid): void
    {
        DB::update($this->refreshWorkspaceSql(), [$workspaceUid]);
    }

    public function refreshAll(): void
    {
        DB::update($this->refreshAllSql());
    }

    private function refreshPageSql(): string
    {
        return $this->refreshSql('WHERE pages.uid = ?');
    }

    private function refreshWorkspaceSql(): string
    {
        return $this->refreshSql('WHERE pages.workspace_uid = ?');
    }

    private function refreshAllSql(): string
    {
        return $this->refreshSql('');
    }

    private function refreshSql(string $whereClause): string
    {
        return sprintf(
            <<<'SQL'
            UPDATE pages
            SET search_vector = %s
            %s
            SQL,
            $this->searchVectorExpressionSql(),
            $whereClause,
        );
    }

    private function searchVectorExpressionSql(): string
    {
        $sql = <<<'SQL'
            (
                setweight(to_tsvector('simple', coalesce(pages.title, '')), 'A')
                || setweight(to_tsvector('simple', coalesce((
                    SELECT string_agg(tags.name || ' ' || tags.slug, ' ')
                    FROM page_tag
                    INNER JOIN tags ON tags.uid = page_tag.tag_uid
                    WHERE page_tag.page_uid = pages.uid
                ), '')), 'A')
                || setweight(to_tsvector('simple', coalesce((
                    SELECT categories.name || ' ' || categories.slug
                    FROM categories
                    WHERE categories.uid = pages.category_uid
                ), '')), 'B')
                || setweight(to_tsvector('simple', coalesce((
                    SELECT workspaces.name
                    FROM workspaces
                    WHERE workspaces.uid = pages.workspace_uid
                ), '')), 'B')
                || setweight(to_tsvector('simple', coalesce((
                    SELECT users.name
                    FROM users
                    WHERE users.uid = pages.owner_user_uid
                ), '')), 'B')
                || setweight(to_tsvector('simple', coalesce(pages.description, '')), 'C')
                || setweight(to_tsvector('simple', replace(pages.type::text, '_', ' ') || ' ' || pages.status::text), 'C')
                || setweight(to_tsvector('simple', coalesce((
                    SELECT string_agg(indexed_provenance.search_text, ' ')
                    FROM (
                        SELECT DISTINCT
                            coalesce(producer_assertions.provider_key, '') || ' '
                            || coalesce(producer_assertions.model_label, '') AS search_text
                        FROM producer_assertions
                        INNER JOIN page_version_ingests
                            ON page_version_ingests.uid = producer_assertions.page_version_ingest_uid
                        WHERE page_version_ingests.page_uid = pages.uid
                          AND NOT EXISTS (
                              SELECT 1
                              FROM producer_assertions AS superseding_assertions
                              WHERE superseding_assertions.supersedes_assertion_uid = producer_assertions.uid
                          )
                        ORDER BY search_text
                        LIMIT __MAX_PROVENANCE_SEARCH_ENTRIES__
                    ) AS indexed_provenance
                ), '')), 'C')
                || setweight(to_tsvector('simple', left(coalesce((
                    SELECT page_versions.extracted_text
                    FROM page_versions
                    WHERE page_versions.uid = pages.current_version_uid
                ), ''), __MAX_EXTRACTED_TEXT_SEARCH_CHARACTERS__)), 'D')
                || setweight(to_tsvector('simple', left(coalesce((
                    SELECT page_versions.source_text
                    FROM page_versions
                    WHERE page_versions.uid = pages.current_version_uid
                ), ''), __MAX_EXTRACTED_TEXT_SEARCH_CHARACTERS__)), 'D')
            )
            SQL;

        return str_replace(
            [
                '__MAX_EXTRACTED_TEXT_SEARCH_CHARACTERS__',
                '__MAX_PROVENANCE_SEARCH_ENTRIES__',
            ],
            [
                (string) self::MAX_EXTRACTED_TEXT_SEARCH_CHARACTERS,
                (string) self::MAX_PROVENANCE_SEARCH_ENTRIES,
            ],
            $sql,
        );
    }
}
