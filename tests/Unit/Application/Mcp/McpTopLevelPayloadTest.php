<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Mcp;

use App\Application\Mcp\McpNotFoundResource;
use App\Application\Mcp\McpToolError;
use App\Application\Mcp\McpToolResult;
use App\Application\Mcp\Output\McpCategoryCreatedPayload;
use App\Application\Mcp\Output\McpCategoryView;
use App\Application\Mcp\Output\McpDescriptionUpdatedPayload;
use App\Application\Mcp\Output\McpExternalShareCreatedPayload;
use App\Application\Mcp\Output\McpHierarchyView;
use App\Application\Mcp\Output\McpPageCreatedPayload;
use App\Application\Mcp\Output\McpPageOrganizedPayload;
use App\Application\Mcp\Output\McpPageView;
use App\Application\Mcp\Output\McpPdfFactsView;
use App\Application\Mcp\Output\McpReadPayload;
use App\Application\Mcp\Output\McpRevertedPayload;
use App\Application\Mcp\Output\McpSearchPayload;
use App\Application\Mcp\Output\McpSearchResultView;
use App\Application\Mcp\Output\McpStoredProvenanceReceipt;
use App\Application\Mcp\Output\McpTagCreatedPayload;
use App\Application\Mcp\Output\McpTagView;
use App\Application\Mcp\Output\McpTaxonomyPayload;
use App\Application\Mcp\Output\McpUntrustedText;
use App\Application\Mcp\Output\McpVersionWrittenPayload;
use App\Application\Mcp\Output\McpWorkspaceListPayload;
use App\Application\Mcp\Output\McpWorkspaceView;
use PHPUnit\Framework\TestCase;

final class McpTopLevelPayloadTest extends TestCase
{
    public function test_page_creation_and_organization_payloads_have_exact_keys(): void
    {
        $page = $this->page();
        $storedProvenance = new McpStoredProvenanceReceipt(false, 'none', []);
        $pageWire = $page->toWire();

        $this->assertSame(array_merge($pageWire, [
            'current_version_uid' => '01VERSION',
            'stored_provenance' => $storedProvenance->toWire(),
        ]), (new McpPageCreatedPayload(
            page: $page,
            currentVersionUid: '01VERSION',
            storedProvenance: $storedProvenance,
        ))->toWire());
        $this->assertSame(array_merge($pageWire, [
            'current_version_uid' => '01VERSION',
            'parent_page_uid' => null,
            'category_uid' => null,
        ]), (new McpPageOrganizedPayload(
            page: $page,
            currentVersionUid: '01VERSION',
            parentPageUid: null,
            categoryUid: null,
        ))->toWire());
    }

    public function test_taxonomy_and_standalone_creation_payloads_have_exact_keys(): void
    {
        $category = new McpCategoryView(
            uid: '01CATEGORY',
            name: new McpUntrustedText('Runbooks'),
            slug: new McpUntrustedText('runbooks'),
            workspaceUid: '01WORKSPACE',
            workspaceName: new McpUntrustedText('Operations'),
        );
        $tag = new McpTagView(
            uid: '01TAG',
            name: new McpUntrustedText('Urgent'),
            slug: new McpUntrustedText('urgent'),
        );

        $this->assertSame([
            'categories' => [$category->toWire()],
            'tags' => [$tag->toWire()],
        ], (new McpTaxonomyPayload([$category], [$tag]))->toWire());
        $this->assertSame([
            'uid' => '01CATEGORY',
            'workspace_uid' => '01WORKSPACE',
            'name' => $category->name->toWire(),
            'slug' => $category->slug->toWire(),
        ], (new McpCategoryCreatedPayload($category))->toWire());
        $this->assertSame([
            'uid' => '01TAG',
            'authority_workspace_uid' => '01WORKSPACE',
            'name' => $tag->name->toWire(),
            'slug' => $tag->slug->toWire(),
        ], (new McpTagCreatedPayload($tag, '01WORKSPACE'))->toWire());
    }

    public function test_version_write_restore_and_description_payloads_have_exact_keys(): void
    {
        $pdf = new McpPdfFactsView(2, '1.7', 'indexed');
        $storedProvenance = new McpStoredProvenanceReceipt(false, 'none', []);

        $this->assertSame([
            'page_uid' => '01PAGE',
            'version_uid' => '01VERSION2',
            'current_version_uid' => '01VERSION2',
            'pdf' => $pdf->toWire(),
            'stored_provenance' => $storedProvenance->toWire(),
        ], (new McpVersionWrittenPayload(
            pageUid: '01PAGE',
            versionUid: '01VERSION2',
            currentVersionUid: '01VERSION2',
            storedProvenance: $storedProvenance,
            pdf: $pdf,
        ))->toWire());
        $this->assertSame([
            'page_uid' => '01PAGE',
            'version_uid' => '01VERSION3',
            'current_version_uid' => '01VERSION3',
            'restored_from_version_uid' => '01VERSION1',
            'pdf' => $pdf->toWire(),
        ], (new McpRevertedPayload(
            pageUid: '01PAGE',
            versionUid: '01VERSION3',
            currentVersionUid: '01VERSION3',
            restoredFromVersionUid: '01VERSION1',
            pdf: $pdf,
        ))->toWire());
        $description = new McpUntrustedText('Visible dashboard');
        $this->assertSame([
            'page_uid' => '01PAGE',
            'current_version_uid' => '01VERSION3',
            'metadata_revision' => 2,
            'description' => $description->toWire(),
        ], (new McpDescriptionUpdatedPayload('01PAGE', '01VERSION3', 2, $description))->toWire());
    }

    public function test_metadata_only_read_payload_has_exact_keys(): void
    {
        $page = $this->page();
        $hierarchy = new McpHierarchyView(null, [], 0, 0);

        $this->assertSame(array_merge($page->toWire(), [
            'current_version_uid' => '01VERSION',
            'hierarchy' => $hierarchy->toWire(),
        ]), (new McpReadPayload(
            page: $page,
            currentVersionUid: '01VERSION',
            currentVersionChangeSummary: null,
            hierarchy: $hierarchy,
        ))->toWire());
    }

    public function test_search_workspace_share_and_error_payloads_have_exact_keys(): void
    {
        $hierarchy = new McpHierarchyView(null, [], 0, 0);
        $result = new McpSearchResultView(
            uid: '01PAGE',
            title: new McpUntrustedText('Runbook'),
            type: 'markdown',
            status: 'approved',
            currentVersionUid: '01VERSION',
            metadataRevision: 0,
            tags: [],
            hierarchy: $hierarchy,
            updatedAt: null,
            snippet: null,
        );
        $workspace = new McpWorkspaceView('01WORKSPACE', new McpUntrustedText('Operations'));

        $this->assertSame(['results' => [$result->toWire()]], (new McpSearchPayload([$result]))->toWire());
        $this->assertSame(
            ['workspaces' => [$workspace->toWire()]],
            (new McpWorkspaceListPayload([$workspace]))->toWire(),
        );
        $this->assertSame([
            'share_uid' => '01SHARE',
            'page_uid' => '01PAGE',
            'mode' => 'one_time',
            'expires_at' => null,
            'created_at' => '2026-08-25T10:00:00.000000Z',
            'url' => 'https://artifactflow.test/external-shares/01SHARE#secret=shown-once',
            'secret_presented_once' => true,
        ], (new McpExternalShareCreatedPayload(
            shareUid: '01SHARE',
            pageUid: '01PAGE',
            mode: 'one_time',
            expiresAt: null,
            createdAt: '2026-08-25T10:00:00.000000Z',
            url: 'https://artifactflow.test/external-shares/01SHARE#secret=shown-once',
        ))->toWire());

        $errorResult = McpToolResult::notFound(McpNotFoundResource::Workspace);
        $this->assertTrue($errorResult->isError);
        $this->assertSame([
            'error' => McpToolError::notFound(McpNotFoundResource::Workspace)->toWire(),
        ], $errorResult->payload->toWire());
    }

    private function page(): McpPageView
    {
        return new McpPageView(
            uid: '01PAGE',
            title: new McpUntrustedText('Runbook'),
            description: new McpUntrustedText('Recovery procedure'),
            type: 'markdown',
            status: 'approved',
            metadataRevision: 0,
            tags: [new McpUntrustedText('Urgent')],
            updatedAt: null,
        );
    }
}
