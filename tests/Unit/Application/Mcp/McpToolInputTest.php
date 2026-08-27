<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Mcp;

use App\Application\Mcp\Input\McpCreateCategoryInput;
use App\Application\Mcp\Input\McpCreateExternalShareInput;
use App\Application\Mcp\Input\McpCreateImageInput;
use App\Application\Mcp\Input\McpCreatePageInput;
use App\Application\Mcp\Input\McpCreatePdfInput;
use App\Application\Mcp\Input\McpCreateTagInput;
use App\Application\Mcp\Input\McpListTaxonomyInput;
use App\Application\Mcp\Input\McpOrganizeInput;
use App\Application\Mcp\Input\McpReadInput;
use App\Application\Mcp\Input\McpReplaceImageInput;
use App\Application\Mcp\Input\McpReplacePdfInput;
use App\Application\Mcp\Input\McpRevertInput;
use App\Application\Mcp\Input\McpSearchInput;
use App\Application\Mcp\Input\McpUpdateContentInput;
use App\Application\Mcp\Input\McpUpdateDescriptionInput;
use App\Application\Mcp\McpProvenanceArguments;
use App\Application\Mcp\McpReadSection;
use App\Application\Mcp\McpToolArguments;
use App\Application\PageCatalog\PageSearchSort;
use App\Domain\DomainRuleViolation;
use App\Domain\ExternalSharing\ExternalShareMode;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\Provenance\ProvenanceSearchScope;
use PHPUnit\Framework\TestCase;

final class McpToolInputTest extends TestCase
{
    public function test_page_creation_input_maps_the_complete_tool_contract(): void
    {
        $input = McpCreatePageInput::fromArguments(McpToolArguments::fromValue([
            'workspace_uid' => '01WORKSPACE',
            'type' => 'markdown',
            'title' => ' Runbook ',
            'content' => '# Runbook',
            'change_summary' => ' Create the runbook. ',
            'description' => ' Recovery steps ',
            'status' => 'approved',
            'category_uid' => '01CATEGORY',
            'parent_page_uid' => '01PARENT',
            'tags' => [' urgent ', 'urgent'],
            'source_filename' => ' runbook.md ',
        ], 'arguments'), new McpProvenanceArguments());

        $this->assertSame('01WORKSPACE', $input->workspaceUid);
        $this->assertSame(PageType::Markdown, $input->type);
        $this->assertSame('Runbook', $input->title);
        $this->assertSame('# Runbook', $input->content);
        $this->assertSame('Create the runbook.', $input->changeSummary);
        $this->assertSame('Recovery steps', $input->description);
        $this->assertSame(PageStatus::Approved, $input->status);
        $this->assertSame('01CATEGORY', $input->categoryUid);
        $this->assertSame('01PARENT', $input->parentPageUid);
        $this->assertSame(['urgent'], $input->tags);
        $this->assertSame('runbook.md', $input->sourceFilename);
        $this->assertNull($input->provenance);
    }

    public function test_binary_creation_inputs_keep_raw_base64_at_the_boundary(): void
    {
        $provenance = new McpProvenanceArguments();
        $image = McpCreateImageInput::fromArguments(McpToolArguments::fromValue([
            'workspace_uid' => '01WORKSPACE',
            'title' => 'Screenshot',
            'image_base64' => ' AA== ',
            'media_type' => 'image/png',
            'change_summary' => 'Create the screenshot.',
        ], 'arguments'), $provenance);
        $pdf = McpCreatePdfInput::fromArguments(McpToolArguments::fromValue([
            'workspace_uid' => '01WORKSPACE',
            'title' => 'Statement',
            'pdf_base64' => ' JVBERg== ',
            'change_summary' => 'Create the statement.',
        ], 'arguments'), $provenance);

        $this->assertSame(' AA== ', $image->encodedImage);
        $this->assertSame('image/png', $image->mediaType);
        $this->assertSame(PageStatus::Draft, $image->status);
        $this->assertSame(' JVBERg== ', $pdf->encodedPdf);
        $this->assertSame(PageStatus::Draft, $pdf->status);
    }

    public function test_binary_replacement_inputs_require_explicit_base_versions(): void
    {
        $provenance = new McpProvenanceArguments();
        $image = McpReplaceImageInput::fromArguments(McpToolArguments::fromValue([
            'page_uid' => '01PAGE',
            'base_version_uid' => '01VERSION',
            'image_base64' => 'AA==',
            'media_type' => 'image/jpeg',
            'change_summary' => 'Replace the screenshot.',
        ], 'arguments'), $provenance);
        $pdf = McpReplacePdfInput::fromArguments(McpToolArguments::fromValue([
            'page_uid' => '01PAGE',
            'base_version_uid' => '01VERSION',
            'pdf_base64' => 'JVBERg==',
            'change_summary' => 'Replace the statement.',
        ], 'arguments'), $provenance);

        $this->assertSame('01VERSION', $image->baseVersionUid);
        $this->assertSame('AA==', $image->encodedImage);
        $this->assertSame('01VERSION', $pdf->baseVersionUid);
        $this->assertSame('JVBERg==', $pdf->encodedPdf);
    }

    public function test_taxonomy_and_revert_inputs_are_specific_named_contracts(): void
    {
        $category = McpCreateCategoryInput::fromArguments(McpToolArguments::fromValue([
            'workspace_uid' => '01WORKSPACE',
            'name' => ' Runbooks ',
        ], 'arguments'));
        $tag = McpCreateTagInput::fromArguments(McpToolArguments::fromValue([
            'workspace_uid' => '01WORKSPACE',
            'name' => ' Urgent ',
        ], 'arguments'));
        $taxonomy = McpListTaxonomyInput::fromArguments(McpToolArguments::fromValue([
            'workspace_uid' => null,
        ], 'arguments'));
        $revert = McpRevertInput::fromArguments(McpToolArguments::fromValue([
            'page_uid' => '01PAGE',
            'base_version_uid' => '01VERSION',
            'change_summary' => 'Restore version one.',
        ], 'arguments'));

        $this->assertSame('Runbooks', $category->name);
        $this->assertSame('Urgent', $tag->name);
        $this->assertNull($taxonomy->workspaceUid);
        $this->assertSame('01VERSION', $revert->baseVersionUid);
    }

    public function test_external_share_input_enforces_mode_specific_expiry(): void
    {
        $oneTime = McpCreateExternalShareInput::fromArguments(McpToolArguments::fromValue([
            'page_uid' => '01PAGE',
            'mode' => 'one_time',
        ], 'arguments'));
        $expiring = McpCreateExternalShareInput::fromArguments(McpToolArguments::fromValue([
            'page_uid' => '01PAGE',
            'mode' => 'expires_at',
            'expires_at' => '2026-08-26T10:00:00+02:00',
        ], 'arguments'));

        $this->assertSame(ExternalShareMode::OneTime, $oneTime->mode);
        $this->assertNull($oneTime->expiresAt);
        $this->assertSame(ExternalShareMode::ExpiresAt, $expiring->mode);
        $this->assertSame('2026-08-26T08:00:00.000000Z', $expiring->expiresAt?->toISOString());
    }

    public function test_read_defaults_to_content_and_provenance(): void
    {
        $input = McpReadInput::fromArguments(McpToolArguments::fromValue([
            'page_uid' => '01PAGE',
        ], 'arguments'));

        $this->assertSame('01PAGE', $input->pageUid);
        $this->assertSame([
            McpReadSection::Content,
            McpReadSection::Provenance,
        ], $input->sections);
        $this->assertTrue($input->includes(McpReadSection::Content));
        $this->assertTrue($input->includes(McpReadSection::Provenance));
    }

    public function test_read_accepts_metadata_only_and_normalizes_duplicate_sections(): void
    {
        $metadataOnly = McpReadInput::fromArguments(McpToolArguments::fromValue([
            'page_uid' => '01PAGE',
            'include' => [],
        ], 'arguments'));
        $contentOnly = McpReadInput::fromArguments(McpToolArguments::fromValue([
            'page_uid' => '01PAGE',
            'include' => ['content', 'content'],
        ], 'arguments'));

        $this->assertSame([], $metadataOnly->sections);
        $this->assertSame([McpReadSection::Content], $contentOnly->sections);
    }

    public function test_read_rejects_an_unknown_section(): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('Argument [include] contains an unsupported read section.');

        McpReadInput::fromArguments(McpToolArguments::fromValue([
            'page_uid' => '01PAGE',
            'include' => ['content', 'unknown'],
        ], 'arguments'));
    }

    public function test_organize_distinguishes_omitted_null_and_empty_fields(): void
    {
        $input = McpOrganizeInput::fromArguments(McpToolArguments::fromValue([
            'page_uid' => '01PAGE',
            'expected_metadata_revision' => 4,
            'parent_page_uid' => null,
            'tags' => [],
        ], 'arguments'));

        $this->assertSame('01PAGE', $input->pageUid);
        $this->assertSame(4, $input->expectedMetadataRevision);
        $this->assertFalse($input->titleProvided);
        $this->assertNull($input->title);
        $this->assertTrue($input->parentPageUidProvided);
        $this->assertNull($input->parentPageUid);
        $this->assertFalse($input->categoryUidProvided);
        $this->assertTrue($input->tagsProvided);
        $this->assertSame([], $input->tags);
        $this->assertTrue($input->hasMutation());
    }

    public function test_search_maps_supported_enums_and_normalized_lists(): void
    {
        $input = McpSearchInput::fromArguments(McpToolArguments::fromValue([
            'query' => ' runbook ',
            'type' => PageType::Markdown->value,
            'status' => PageStatus::Approved->value,
            'tag_uids' => [' 01TAG ', '', '01TAG'],
            'provenance_scope' => ProvenanceSearchScope::CurrentVersion->value,
            'include_archived' => true,
            'include_snippet' => true,
            'sort' => PageSearchSort::RecentlyUpdated->value,
        ], 'arguments'));

        $this->assertSame('runbook', $input->query);
        $this->assertSame(PageType::Markdown, $input->type);
        $this->assertSame(PageStatus::Approved, $input->status);
        $this->assertSame(['01TAG'], $input->tagUids);
        $this->assertSame(ProvenanceSearchScope::CurrentVersion, $input->provenanceScope);
        $this->assertTrue($input->includeArchived);
        $this->assertTrue($input->includeSnippet);
        $this->assertSame(PageSearchSort::RecentlyUpdated, $input->sort);
    }

    public function test_search_preserves_the_relevance_fallback_for_an_unknown_sort(): void
    {
        $input = McpSearchInput::fromArguments(McpToolArguments::fromValue([
            'sort' => 'future-sort-value',
        ], 'arguments'));

        $this->assertSame(PageSearchSort::Relevance, $input->sort);
    }

    public function test_update_content_preserves_an_omitted_base_version(): void
    {
        $input = McpUpdateContentInput::fromArguments(
            McpToolArguments::fromValue([
                'page_uid' => '01PAGE',
                'content' => '# Replacement',
                'change_summary' => 'Replace the source.',
            ], 'arguments'),
            new McpProvenanceArguments(),
        );

        $this->assertNull($input->baseVersionUid);
    }

    public function test_update_description_captures_both_concurrency_dimensions(): void
    {
        $input = McpUpdateDescriptionInput::fromArguments(McpToolArguments::fromValue([
            'page_uid' => '01PAGE',
            'expected_current_version_uid' => '01VERSION',
            'expected_metadata_revision' => 7,
            'description' => ' Visible dashboard ',
        ], 'arguments'));

        $this->assertSame('01PAGE', $input->pageUid);
        $this->assertSame('01VERSION', $input->expectedCurrentVersionUid);
        $this->assertSame(7, $input->expectedMetadataRevision);
        $this->assertSame('Visible dashboard', $input->description);
    }
}
