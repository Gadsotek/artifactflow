<?php

declare(strict_types=1);

namespace App\Application\PageCatalog;

use App\Application\Administration\RealtimeConfiguration;
use App\Domain\PageCatalog\PageStatus;
use App\Models\Category;
use App\Models\Page;
use App\Models\PageVersion;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;

final readonly class PageDetailViewData
{
    public function __construct(
        private PageAccess $access,
        private PageActivity $pageActivity,
        private PageHierarchy $pageHierarchy,
        private PageDetailContent $content,
        private PageDetailMetadataOptions $metadataOptions,
        private PageDetailAccessOptions $accessOptions,
        private PageWorkspaceMoveOptions $moveOptions,
        private RealtimeConfiguration $realtimeConfiguration,
    ) {
    }

    /**
     * @return array{
     *     artifactPreviewUrl: string|null,
     *     baseVersionUid: string|null,
     *     canArchive: bool,
     *     canDelete: bool,
     *     canEdit: bool,
     *     canMoveWorkspace: bool,
     *     canMutateContent: bool,
     *     canManageAccess: bool,
     *     category: Category|null,
     *     contentUnavailable: bool,
     *     metadataCategories: list<Category>,
     *     metadataOwners: list<User>,
     *     metadataParentPages: list<Page>,
     *     metadataTagNames: string,
     *     page: Page,
     *     pageActivity: list<PageActivityItem>,
     *     pageHierarchy: PageHierarchyResult,
     *     pageMoveTargets: list<PageWorkspaceMoveTarget>,
     *     pageAccessGrants: list<PageAccessGrantItem>,
     *     pageAccessWorkspaceTargets: list<PageAccessWorkspaceTargetItem>,
     *     pagePresenceActorName: string,
     *     pagePresenceActorUid: string,
     *     pagePresenceEnabled: bool,
     *     renderedEditorMarkdown: string|null,
     *     renderedMarkdown: string|null,
     *     sourcePreview: string|null,
     *     tags: list<Tag>,
     *     version: PageVersion|null,
     *     versions: list<PageVersion>,
     *     workspace: Workspace|null
     * }
     */
    public function forPage(User $actor, Page $page): array
    {
        $canEdit = $this->access->canEdit($actor, $page);
        $canMutateContent = $canEdit && $page->status !== PageStatus::Archived;
        $content = $this->content->forPage($actor, $page, $canMutateContent);
        $tags = array_values($page->tags()->orderBy('name')->get()->all());
        $canManageAccess = $this->access->canManageAccess($actor, $page);
        $pageMoveTargets = $this->moveOptions->forPage($actor, $page);

        return [
            'artifactPreviewUrl' => $content->artifactPreviewUrl,
            'baseVersionUid' => $page->current_version_uid,
            'canArchive' => $this->access->canArchive($actor, $page),
            'canDelete' => $this->access->canHardDelete($actor, $page),
            'canEdit' => $canEdit,
            'canMoveWorkspace' => $pageMoveTargets !== [],
            'canMutateContent' => $canMutateContent,
            'canManageAccess' => $canManageAccess,
            'category' => $page->category_uid === null ? null : Category::query()->find($page->category_uid),
            'contentUnavailable' => $content->contentUnavailable,
            // The pickers only render inside the canEdit metadata form; skip their
            // queries entirely for viewers who cannot edit.
            'metadataCategories' => $canEdit ? $this->metadataOptions->categoriesFor([$page->workspace_uid]) : [],
            'metadataOwners' => $this->metadataOwnerOptions($actor, $page, $canEdit),
            'metadataParentPages' => $canEdit ? $this->metadataOptions->parentPagesFor($actor, $page) : [],
            'metadataTagNames' => implode(', ', array_map(
                static fn (Tag $tag): string => $tag->name,
                $tags,
            )),
            'page' => $page,
            'pageActivity' => $this->pageActivity->forPage($page),
            'pageHierarchy' => $this->pageHierarchy->forPage($actor, $page),
            'pageMoveTargets' => $pageMoveTargets,
            'pageAccessGrants' => $canManageAccess ? $this->accessOptions->grantsFor($page) : [],
            'pageAccessWorkspaceTargets' => $canManageAccess ? $this->accessOptions->workspaceTargetsFor($actor) : [],
            'pagePresenceActorName' => $actor->name,
            'pagePresenceActorUid' => $actor->uid,
            'pagePresenceEnabled' => $this->realtimeConfiguration->enabled(),
            'renderedEditorMarkdown' => $content->renderedEditorMarkdown,
            'renderedMarkdown' => $content->renderedMarkdown,
            'sourcePreview' => $content->sourcePreview,
            'tags' => $tags,
            'version' => $content->version,
            'versions' => $this->versionsFor($page),
            'workspace' => Workspace::query()->find($page->workspace_uid),
        ];
    }

    /**
     * The owner picker only renders inside the canEdit metadata form. Reassigning
     * ownership needs page-admin authority, so a non-admin editor sees only the
     * current owner: exposing the workspace's Editor/Admin roster would disclose
     * membership to a page-only editor who is not a workspace member.
     *
     * @return list<User>
     */
    private function metadataOwnerOptions(User $actor, Page $page, bool $canEdit): array
    {
        if (!$canEdit) {
            return [];
        }

        if ($this->access->canTransferOwnership($actor, $page)) {
            return $this->metadataOptions->eligibleOwnersFor([$page->workspace_uid]);
        }

        return $this->metadataOptions->currentOwnerOptionFor($page);
    }

    /**
     * @return list<PageVersion>
     */
    private function versionsFor(Page $page): array
    {
        // Project only the columns the history dialog renders. source_text/extracted_text
        // hold up to MAX_EXTRACTED_TEXT_SEARCH_CHARACTERS per version and are never shown
        // here; hydrating them for every version would bloat every page-show request.
        return array_values(PageVersion::query()
            ->select([
                'uid',
                'page_uid',
                'version_number',
                'content_hash',
                'byte_size',
                'scan_status',
                'created_by_user_uid',
                'created_at',
            ])
            ->with('creator')
            ->where('page_uid', $page->uid)
            ->orderByDesc('version_number')
            ->get()
            ->all());
    }
}
