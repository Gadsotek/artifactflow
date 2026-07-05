<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Identity\WorkspaceContext;
use App\Application\Identity\WorkspaceInvitationOverview;
use App\Application\Identity\WorkspaceNavigationItem;
use App\Application\PageCatalog\ArtifactPreviewUrl;
use App\Application\PageCatalog\CreatePage;
use App\Application\PageCatalog\CreatePageCommand;
use App\Application\PageCatalog\MarkdownPageRenderer;
use App\Application\PageCatalog\PageAccess;
use App\Application\PageCatalog\PageDetailViewData;
use App\Application\PageCatalog\PageFilterTaxonomy;
use App\Application\PageCatalog\PageHierarchyPresenter;
use App\Application\PageCatalog\PageLibraryWorkspaceOptions;
use App\Application\PageCatalog\PagePickerOptions;
use App\Application\PageCatalog\PageSearch;
use App\Application\PageCatalog\PageSearchFilters;
use App\Application\PageCatalog\PageSearchSort;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\CategoryRuleViolation;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Http\Requests\PageCatalog\StorePageRequest;
use App\Models\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class PageController
{
    use Concerns\ResolvesAuthenticatedUser;

    public function __construct(
        private readonly MarkdownPageRenderer $markdownRenderer,
        private readonly PageSearch $pageSearch,
        private readonly WorkspaceContext $workspaceContext,
        private readonly PageAccess $access,
        private readonly WorkspaceInvitationOverview $workspaceInvitations,
        private readonly PageDetailViewData $pageDetailViewData,
        private readonly PageHierarchyPresenter $hierarchyPresenter,
        private readonly PageLibraryWorkspaceOptions $libraryWorkspaceOptions,
        private readonly PagePickerOptions $pickerOptions,
        private readonly PageFilterTaxonomy $filterTaxonomy,
    ) {
    }

    public function index(Request $request): View
    {
        $user = $this->authenticatedUser($request);
        $membershipItems = $this->workspaceContext->itemsFor($user);
        $workspaceItems = $this->libraryWorkspaceOptions->forUser($user, $membershipItems);
        $currentWorkspaceUid = $this->workspaceContext->resolveCurrentWorkspaceUid($request, $workspaceItems, true);
        $filters = $this->pageSearchFiltersFrom($request, $currentWorkspaceUid);
        $filterWorkspaceUids = $this->filterOptionWorkspaceUidsFor($currentWorkspaceUid, $membershipItems);
        $pages = $this->hierarchyPresenter->arrange($user, $this->pageSearch->search($user, $filters));
        $taxonomy = $this->filterTaxonomy->forUser($user, $filters->workspaceUid);

        return view('pages.index', [
            'categories' => $taxonomy->categories,
            'canInviteToCurrentWorkspace' => $this->workspaceInvitations->canInviteToWorkspace(
                $user,
                $currentWorkspaceUid,
            ),
            'currentWorkspaceUid' => $currentWorkspaceUid,
            'filters' => $filters,
            'owners' => $this->pickerOptions->ownersFor($filterWorkspaceUids),
            'pages' => $pages,
            'pageStatuses' => PageStatus::cases(),
            'pageTypes' => PageType::cases(),
            'showCategoryWorkspaceNames' => $filters->workspaceUid === PageSearchFilters::ALL_WORKSPACES,
            'tags' => $taxonomy->tags,
            'workspaces' => $workspaceItems,
            'workspaceInvitationRoles' => $this->workspaceInvitations->allowedInvitationRoles(
                $user,
                $currentWorkspaceUid,
            ),
        ]);
    }

    public function create(Request $request): View
    {
        $user = $this->authenticatedUser($request);
        $editableWorkspaces = $this->workspaceContext->editableItemsFor($user);
        $editableWorkspaceUids = $this->workspaceContext->uidsFrom($editableWorkspaces);
        $selectedWorkspaceUid = $this->workspaceContext->resolveCurrentWorkspaceUid(
            $request,
            $editableWorkspaces,
            false,
        );
        $oldWorkspaceUid = $request->old('workspace_uid');

        if (is_string($oldWorkspaceUid) && in_array($oldWorkspaceUid, $editableWorkspaceUids, true)) {
            $selectedWorkspaceUid = $oldWorkspaceUid;
        }

        $parentPages = $this->pickerOptions->parentPagesFor($user, $editableWorkspaceUids);
        $selectedParentPageUid = $this->selectedParentPageUid(
            $request,
            $parentPages,
            $selectedWorkspaceUid,
        );
        $oldContent = $request->old('content');
        $oldType = $request->old('type', PageType::Markdown->value);
        $renderedEditorMarkdown = is_string($oldContent)
            && $oldType === PageType::Markdown->value
            && trim($oldContent) !== ''
                ? $this->markdownRenderer->render($oldContent)
                : '';

        return view('pages.create', [
            'categories' => $this->pickerOptions->categoriesFor($editableWorkspaceUids),
            'draftPreviewUrl' => app(ArtifactPreviewUrl::class)->draftEndpointUrl(),
            'editableWorkspaces' => $editableWorkspaces,
            'parentPages' => $parentPages,
            'renderedEditorMarkdown' => $renderedEditorMarkdown,
            'selectedParentPageUid' => $selectedParentPageUid,
            'selectedWorkspaceUid' => $selectedWorkspaceUid,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(StorePageRequest $request, CreatePage $createPage): RedirectResponse
    {
        $user = $this->authenticatedUser($request);

        try {
            $page = $createPage->handle($user, new CreatePageCommand(
                workspaceUid: $request->string('workspace_uid')->toString(),
                type: $request->pageType(),
                title: $request->string('title')->toString(),
                description: $this->nullableString($request, 'description'),
                content: $request->pageContent(),
                status: $request->pageStatus(),
                categoryUid: $this->nullableString($request, 'category_uid'),
                parentPageUid: $this->nullableString($request, 'parent_page_uid'),
                ownerUserUid: null,
                tagNames: $request->tagNames(),
                sourceFilename: $request->sourceFilename(),
                source: $request->pageVersionSource(),
                categoryName: $this->nullableString($request, 'category_name'),
            ));
        } catch (CategoryRuleViolation $exception) {
            throw ValidationException::withMessages([
                'category_name' => $exception->getMessage(),
            ]);
        } catch (DomainRuleViolation $exception) {
            throw ValidationException::withMessages([
                'content' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('pages.show', $page);
    }

    public function show(Request $request, Page $page): View
    {
        $user = $this->authenticatedUser($request);
        $this->access->ensureCanView($user, $page);

        return view('pages.show', $this->pageDetailViewData->forPage($user, $page));
    }

    private function pageSearchFiltersFrom(Request $request, ?string $currentWorkspaceUid): PageSearchFilters
    {
        $type = $this->nullableString($request, 'type');
        $status = $this->nullableString($request, 'status');

        return new PageSearchFilters(
            query: $this->nullableString($request, 'q'),
            workspaceUid: $this->nullableString($request, 'workspace_uid') ?? $currentWorkspaceUid,
            type: $type === null ? null : PageType::tryFrom($type),
            status: $status === null ? null : PageStatus::tryFrom($status),
            categoryUid: $this->nullableString($request, 'category_uid'),
            tagUids: $this->tagUidsFrom($request),
            ownerUserUid: $this->nullableString($request, 'owner_user_uid'),
            includeArchived: $request->boolean('include_archived'),
            sort: PageSearchSort::tryFrom($request->string('sort')->toString())
                ?? PageSearchSort::Relevance,
        );
    }

    /**
     * @return list<string>
     */
    private function tagUidsFrom(Request $request): array
    {
        $input = $request->input('tag_uids', []);
        $tagUids = is_array($input) ? $input : [$input];

        $normalized = [];

        foreach ($tagUids as $tagUid) {
            if (!is_string($tagUid)) {
                continue;
            }

            $tagUid = trim($tagUid);

            if ($tagUid === '' || in_array($tagUid, $normalized, true)) {
                continue;
            }

            $normalized[] = $tagUid;

            if (count($normalized) === 20) {
                break;
            }
        }

        return $normalized;
    }

    /**
     * @param list<WorkspaceNavigationItem> $workspaceItems
     *
     * @return list<string>
     */
    private function filterOptionWorkspaceUidsFor(?string $currentWorkspaceUid, array $workspaceItems): array
    {
        $workspaceUids = array_map(
            static fn (WorkspaceNavigationItem $item): string => $item->uid,
            $workspaceItems,
        );

        if ($currentWorkspaceUid === PageSearchFilters::ALL_WORKSPACES) {
            return $workspaceUids;
        }

        if ($currentWorkspaceUid !== null && in_array($currentWorkspaceUid, $workspaceUids, true)) {
            return [$currentWorkspaceUid];
        }

        return $workspaceUids;
    }

    private function nullableString(Request $request, string $field): ?string
    {
        $value = trim($request->string($field)->toString());

        return $value === '' ? null : $value;
    }

    /**
     * @param list<Page> $parentPages
     */
    private function selectedParentPageUid(
        Request $request,
        array $parentPages,
        ?string $selectedWorkspaceUid,
    ): ?string {
        $oldParentPageUid = $request->old('parent_page_uid');
        $requestedParentPageUid = is_string($oldParentPageUid)
            ? trim($oldParentPageUid)
            : trim($request->string('parent_page_uid')->toString());

        if ($requestedParentPageUid === '' || $selectedWorkspaceUid === null) {
            return null;
        }

        foreach ($parentPages as $parentPage) {
            if (
                $parentPage->uid === $requestedParentPageUid
                && $parentPage->workspace_uid === $selectedWorkspaceUid
            ) {
                return $parentPage->uid;
            }
        }

        return null;
    }
}
