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
use App\Application\PageCatalog\PageFilterProvenance;
use App\Application\PageCatalog\PageFilterTaxonomy;
use App\Application\PageCatalog\PageHierarchyPresenter;
use App\Application\PageCatalog\PageLibraryWorkspaceOptions;
use App\Application\PageCatalog\PagePickerOptions;
use App\Application\PageCatalog\PageSearch;
use App\Application\PageCatalog\PageSearchFilters;
use App\Application\PageCatalog\PageSearchSort;
use App\Domain\DomainRuleViolation;
use App\Domain\PageCatalog\CategoryRuleViolation;
use App\Domain\PageCatalog\ImageNormalizationRejected;
use App\Domain\PageCatalog\PageStatus;
use App\Domain\PageCatalog\PageType;
use App\Domain\Provenance\ProvenanceSearchScope;
use App\Http\Requests\PageCatalog\StorePageRequest;
use App\Http\Support\ImageNormalizationRejectionResponse;
use App\Models\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

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
        private readonly PageFilterProvenance $filterProvenance,
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
        $provenance = $this->filterProvenance->forUser($user, $filters->workspaceUid);

        return view('pages.index', [
            'categories' => $taxonomy->categories,
            'canInviteToCurrentWorkspace' => $this->workspaceInvitations->canInviteToWorkspace(
                $user,
                $currentWorkspaceUid,
            ),
            'currentWorkspaceUid' => $currentWorkspaceUid,
            'user' => $user,
            'filters' => $filters,
            'owners' => $this->pickerOptions->ownersFor($filterWorkspaceUids),
            'pages' => $pages,
            'provenanceModels' => $provenance->models,
            'provenanceProviders' => $provenance->providers,
            'pageStatuses' => PageStatus::cases(),
            'pageTypes' => PageType::cases(),
            'showCategoryWorkspaceNames' => $filters->workspaceUid === PageSearchFilters::ALL_WORKSPACES,
            'showResultWorkspaceNames' => $filters->workspaceUid === PageSearchFilters::ALL_WORKSPACES,
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
            'draftPreviewCapabilityUrl' => route('artifact-previews.draft-capabilities.store'),
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
    public function store(StorePageRequest $request, CreatePage $createPage): RedirectResponse|Response
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
                changeSummary: null,
            ));
        } catch (ImageNormalizationRejected $exception) {
            return ImageNormalizationRejectionResponse::make($exception);
        } catch (CategoryRuleViolation $exception) {
            throw ValidationException::withMessages([
                'category_name' => $exception->getMessage(),
            ]);
        } catch (DomainRuleViolation $exception) {
            $field = $request->pageType() === PageType::Image ? 'image_file' : 'content';

            throw ValidationException::withMessages([
                $field => $exception->getMessage(),
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
        $provenanceScope = ProvenanceSearchScope::tryFrom(
            $this->nullableString($request, 'provenance_scope') ?? ProvenanceSearchScope::AnyVersion->value,
        ) ?? ProvenanceSearchScope::AnyVersion;

        return new PageSearchFilters(
            query: $this->nullableString($request, 'q'),
            workspaceUid: $this->nullableString($request, 'workspace_uid') ?? $currentWorkspaceUid,
            type: $type === null ? null : PageType::tryFrom($type),
            statuses: $this->statusesFrom($request),
            categoryUids: $this->stringListWithLegacy($request, 'category_uids', 'category_uid', 20),
            tagUids: $this->stringListWithLegacy($request, 'tag_uids', 'tag_uid', 20),
            ownerUserUid: $this->nullableString($request, 'owner_user_uid'),
            sort: PageSearchSort::tryFrom($this->nullableString($request, 'sort') ?? '')
                ?? ($this->nullableString($request, 'q') === null
                    ? PageSearchSort::RecentlyUpdated
                    : PageSearchSort::Relevance),
            aiProviders: $this->stringListWithLegacy($request, 'ai_providers', 'ai_provider', 20),
            aiModelIds: $this->stringListFrom($request, 'ai_model_ids', 50),
            aiModelQuery: $this->nullableString($request, 'ai_model_query'),
            provenanceScope: $provenanceScope,
        );
    }

    /** @return list<PageStatus> */
    private function statusesFrom(Request $request): array
    {
        if (!$request->has('statuses')) {
            $legacyStatus = $this->nullableString($request, 'status');

            if ($legacyStatus !== null) {
                $status = PageStatus::tryFrom($legacyStatus);

                return $status instanceof PageStatus ? [$status] : PageSearchFilters::activeStatuses();
            }

            return $request->boolean('include_archived')
                ? PageStatus::cases()
                : PageSearchFilters::activeStatuses();
        }

        $statuses = [];

        foreach ($this->stringListFrom($request, 'statuses', count(PageStatus::cases())) as $value) {
            $status = PageStatus::tryFrom($value);

            if ($status instanceof PageStatus) {
                $statuses[] = $status;
            }
        }

        return $statuses;
    }

    /**
     * @return list<string>
     */
    private function stringListWithLegacy(
        Request $request,
        string $field,
        string $legacyField,
        int $maximum,
    ): array {
        if ($request->has($field)) {
            return $this->stringListFrom($request, $field, $maximum);
        }

        $legacyValue = $this->nullableString($request, $legacyField);

        return $legacyValue === null ? [] : [$legacyValue];
    }

    /**
     * @return list<string>
     */
    private function stringListFrom(Request $request, string $field, int $maximum): array
    {
        $input = $request->input($field, []);
        $values = is_array($input) ? $input : [$input];

        $normalized = [];

        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($value === '' || in_array($value, $normalized, true)) {
                continue;
            }

            $normalized[] = $value;

            if (count($normalized) === $maximum) {
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
        $value = $request->input($field);

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new UnprocessableEntityHttpException(sprintf(
                'Query parameter [%s] must be a string.',
                $field,
            ));
        }

        $value = trim($value);

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
            : $this->nullableString($request, 'parent_page_uid') ?? '';

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
