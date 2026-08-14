@use('App\Application\PageCatalog\PageSearchFilters')
@use('App\Application\PageCatalog\PageSearchSort')
@use('App\Domain\Provenance\ProvenanceSearchScope')
<x-layouts.app title="Pages">
    <div class="af-app-surface min-h-screen bg-zinc-50 dark:bg-zinc-950">
        <header class="af-page-header border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
            <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4">
                <div>
                    <p class="af-eyebrow">Knowledge library</p>
                    <h1 class="text-xl font-semibold text-zinc-950 dark:text-zinc-50">Pages</h1>
                    <p class="af-page-intro">Search across trusted documentation and isolated interactive artifacts.</p>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-2">
                    @if ($canInviteToCurrentWorkspace)
                        <button class="af-secondary-button" data-open-editor-dialog="library-workspace-invite-dialog" type="button">Invite teammate</button>
                    @endif
                    <a class="af-primary-button" href="{{ route('pages.create', $currentWorkspaceUid === 'all' ? [] : ['workspace_uid' => $currentWorkspaceUid]) }}">Create page</a>
                </div>
            </div>
        </header>

        <main class="af-page-grid mx-auto grid max-w-7xl gap-8 px-6 py-8 lg:grid-cols-[18rem_1fr]">
            <aside class="af-context-panel">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500">Workspaces</h2>
                    <button
                        class="af-icon-button"
                        data-open-editor-dialog="library-workspace-create-dialog"
                        type="button"
                        aria-label="Create workspace"
                        title="Create workspace"
                    >+</button>
                </div>
                <div class="mt-3 space-y-2">
                    <a class="block rounded-md border px-3 py-2 text-sm {{ $currentWorkspaceUid === 'all' ? 'af-option-active' : 'border-zinc-200 bg-white text-zinc-800 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100' }}" href="{{ route('pages.index', ['workspace_uid' => 'all']) }}">
                        <span class="font-medium">All visible</span>
                    </a>
                    @foreach ($workspaces as $workspace)
                        <a class="block rounded-md border px-3 py-2 text-sm {{ $workspace->uid === $currentWorkspaceUid ? 'af-option-active' : 'border-zinc-200 bg-white text-zinc-800 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-100' }}" href="{{ route('pages.index', ['workspace_uid' => $workspace->uid]) }}">
                            <span class="flex items-center justify-between gap-2">
                                <span class="font-medium">{{ $workspace->name }}</span>
                                @if (!$workspace->isMembership && $workspace->accessLabel !== null)
                                    <span class="text-[0.65rem] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ $workspace->accessLabel }}</span>
                                @endif
                            </span>
                        </a>
                    @endforeach
                </div>
            </aside>

            <section class="space-y-6">
                <form class="space-y-3" method="GET" action="{{ route('pages.index') }}">
                    <div class="af-filter-panel grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <label class="space-y-1 text-sm xl:col-span-2">
                            <span class="font-medium text-zinc-700 dark:text-zinc-300">Search</span>
                            <input class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50" name="q" value="{{ $filters->query }}" type="search">
                        </label>

                        @if ($currentWorkspaceUid === PageSearchFilters::ALL_WORKSPACES)
                            <label class="space-y-1 text-sm">
                                <span class="font-medium text-zinc-700 dark:text-zinc-300">Workspace</span>
                                <select class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50" data-library-workspace-filter name="workspace_uid">
                                    <option value="all" @selected($filters->workspaceUid === PageSearchFilters::ALL_WORKSPACES)>All visible</option>
                                    @foreach ($workspaces as $workspace)
                                        <option value="{{ $workspace->uid }}" @selected($filters->workspaceUid === $workspace->uid)>{{ $workspace->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @else
                            <input name="workspace_uid" type="hidden" value="{{ $currentWorkspaceUid }}">
                        @endif

                        <label class="space-y-1 text-sm">
                            <span class="font-medium text-zinc-700 dark:text-zinc-300">Type</span>
                            <select class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50" name="type">
                                <option value="">Any type</option>
                                @foreach ($pageTypes as $pageType)
                                    <option value="{{ $pageType->value }}" @selected($filters->type === $pageType)>{{ str_replace('_', ' ', $pageType->value) }}</option>
                                @endforeach
                            </select>
                        </label>

                        <div class="space-y-1 text-sm">
                            <label class="block font-medium text-zinc-700 dark:text-zinc-300" for="library-statuses">Statuses</label>
                            <select class="w-full" data-multi-select data-placeholder="Select statuses" id="library-statuses" name="statuses[]" multiple size="4">
                                @foreach ($pageStatuses as $pageStatus)
                                    <option value="{{ $pageStatus->value }}" @selected(in_array($pageStatus, $filters->statuses, true))>{{ ucfirst($pageStatus->value) }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="space-y-1 text-sm">
                            <label class="block font-medium text-zinc-700 dark:text-zinc-300" for="library-categories">Categories</label>
                            <select class="w-full" data-multi-select data-searchable-multi-select data-placeholder="Any category" data-search-placeholder="Search categories" id="library-categories" name="category_uids[]" multiple size="4">
                                @foreach ($categories as $category)
                                    <option value="{{ $category->uid }}" @selected(in_array($category->uid, $filters->categoryUids, true))>{{ $category->name }}@if ($showCategoryWorkspaceNames) — {{ $category->workspace->name }}@endif</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="space-y-1 text-sm">
                            <label class="block font-medium text-zinc-700 dark:text-zinc-300" for="library-tags">Tags</label>
                            <select class="w-full" data-multi-select data-searchable-multi-select data-placeholder="Any tag" data-search-placeholder="Search tags" id="library-tags" name="tag_uids[]" multiple size="4">
                                @foreach ($tags as $tag)
                                    <option value="{{ $tag->uid }}" @selected(in_array($tag->uid, $filters->tagUids, true))>{{ $tag->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <label class="space-y-1 text-sm">
                            <span class="font-medium text-zinc-700 dark:text-zinc-300">Owner</span>
                            <select class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50" name="owner_user_uid">
                                <option value="">Any owner</option>
                                @foreach ($owners as $owner)
                                    <option value="{{ $owner->uid }}" @selected($filters->ownerUserUid === $owner->uid)>{{ $owner->name }}</option>
                                @endforeach
                            </select>
                        </label>

                        <div class="space-y-1 text-sm">
                            <label class="block font-medium text-zinc-700 dark:text-zinc-300" for="library-ai-providers">AI providers</label>
                            <select class="w-full" data-multi-select data-searchable-multi-select data-placeholder="Any provider" data-search-placeholder="Search providers" id="library-ai-providers" name="ai_providers[]" multiple size="4">
                                @foreach ($provenanceProviders as $provider)
                                    <option value="{{ $provider->value }}" @selected(in_array($provider->value, $filters->aiProviders, true))>{{ $provider->label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="space-y-1 text-sm">
                            <label class="block font-medium text-zinc-700 dark:text-zinc-300" for="library-ai-models">AI models</label>
                            <select class="w-full" data-multi-select data-searchable-multi-select data-placeholder="Any model" data-search-placeholder="Search models" id="library-ai-models" name="ai_model_ids[]" multiple size="4">
                                @foreach ($provenanceModels as $model)
                                    <option value="{{ $model->value }}" @selected(in_array($model->value, $filters->aiModelIds, true))>{{ $model->label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <label class="space-y-1 text-sm">
                            <span class="font-medium text-zinc-700 dark:text-zinc-300">Provenance scope</span>
                            <select class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-50" name="provenance_scope">
                                <option value="{{ ProvenanceSearchScope::AnyVersion->value }}" @selected($filters->provenanceScope === ProvenanceSearchScope::AnyVersion)>Any retained provenance</option>
                                <option value="{{ ProvenanceSearchScope::CurrentVersion->value }}" @selected($filters->provenanceScope === ProvenanceSearchScope::CurrentVersion)>Current version</option>
                                <option value="{{ ProvenanceSearchScope::PageOrigin->value }}" @selected($filters->provenanceScope === ProvenanceSearchScope::PageOrigin)>Page origin</option>
                            </select>
                        </label>

                        <div class="flex items-end justify-end gap-3 xl:col-span-4">
                            <a class="af-secondary-button" href="{{ route('pages.index', ['workspace_uid' => $currentWorkspaceUid]) }}">Clear</a>
                            <button class="af-primary-button" name="sort" type="submit" value="{{ $filters->sort->value }}">Apply filters</button>
                        </div>
                    </div>

                    <div class="af-library-sort-switches" data-library-sort-switches>
                        <span>Sort by</span>
                        <div>
                            @if ($filters->hasQuery() || $filters->sort === PageSearchSort::Relevance)
                                <button data-sort-active="{{ $filters->sort === PageSearchSort::Relevance ? 'true' : 'false' }}" name="sort" type="submit" value="{{ PageSearchSort::Relevance->value }}">Best match</button>
                            @endif
                            <button data-sort-active="{{ $filters->sort === PageSearchSort::RecentlyUpdated ? 'true' : 'false' }}" name="sort" type="submit" value="{{ PageSearchSort::RecentlyUpdated->value }}">Recently updated</button>
                            <button data-sort-active="{{ $filters->sort === PageSearchSort::Title ? 'true' : 'false' }}" name="sort" type="submit" value="{{ PageSearchSort::Title->value }}">Title</button>
                        </div>
                    </div>
                </form>

                <div
                    data-live-page-catalog
                    data-live-page-catalog-user-uid="{{ $user->uid }}"
                    data-live-page-catalog-workspace-uid="{{ $currentWorkspaceUid }}"
                >
                    @if ($pages === [])
                        <div class="border-t border-zinc-200 pt-8 dark:border-zinc-800">
                        <h2 class="text-lg font-semibold text-zinc-950 dark:text-zinc-50">No pages found</h2>
                        <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-400">Create a page or adjust the current filters.</p>
                        </div>
                    @else
                        <div class="af-result-list divide-y divide-zinc-200 border-y border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                        @foreach ($pages as $libraryItem)
                            @php($result = $libraryItem->result)
                            @php($page = $result->page)
                            <a
                                @class([
                                    'af-library-page block transition hover:bg-zinc-100 dark:hover:bg-zinc-900',
                                    'af-library-page-child' => $libraryItem->depth > 0,
                                    'af-library-page-depth-'.$libraryItem->visualDepth => $libraryItem->depth > 0,
                                ])
                                data-page-hierarchy-depth="{{ $libraryItem->depth }}"
                                data-page-new="{{ $result->isNew ? 'true' : 'false' }}" data-page-uid="{{ $page->uid }}"
                                href="{{ route('pages.show', $page) }}"
                            >
                                <div class="af-library-page-layout">
                                    @if ($libraryItem->parentTitle !== null)
                                        <span class="af-library-page-branch" aria-hidden="true">↳</span>
                                    @endif
                                    <div class="min-w-0 flex-1">
                                        @if ($libraryItem->parentTitle !== null)
                                            <p class="af-library-page-parent">Under {{ $libraryItem->parentTitle }}</p>
                                        @endif
                                        <div class="flex items-center justify-between gap-4">
                                            <h2 class="text-base font-semibold text-zinc-950 dark:text-zinc-50">{{ $page->title }}</h2>
                                            <span class="flex items-center gap-2">
                                                @if ($result->isNew)
                                                    <span class="af-new-page-badge" data-page-new-indicator>NEW</span>
                                                @endif
                                                <span class="text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ $page->type->value }}</span>
                                            </span>
                                        </div>
                                        @if ($result->snippet !== null)
                                            {{-- Snippets may be derived from untrusted content; keep Blade escaping here. --}}
                                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $result->snippet }}</p>
                                        @endif
                                        <div class="mt-2 flex flex-wrap gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                            <span>{{ ucfirst($page->status->value) }}</span>
                                            @if ($showResultWorkspaceNames && $result->workspaceName !== null)
                                                <span>{{ $result->workspaceName }}</span>
                                            @endif
                                            @if ($page->category !== null)
                                                <span>{{ $page->category->name }}</span>
                                            @endif
                                            <span>{{ $page->owner->name }}</span>
                                            <span>Updated {{ $page->updated_at->toDateString() }}</span>
                                            @foreach ($page->tags as $tag)
                                                <span>{{ $tag->name }}</span>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </a>
                        @endforeach
                        </div>
                    @endif
                </div>
            </section>
        </main>

        @if ($canInviteToCurrentWorkspace)
            <x-workspace-invite-dialog
                dialog-id="library-workspace-invite-dialog"
                :workspace-uid="$currentWorkspaceUid"
                :roles="$workspaceInvitationRoles"
                return-to="library"
            />
        @endif

        <dialog class="artifactflow-editor-dialog af-compact-dialog" data-editor-dialog id="library-workspace-create-dialog" aria-labelledby="library-workspace-create-dialog-title">
            <div class="artifactflow-editor-dialog-panel">
                <div class="af-dialog-header">
                    <div>
                        <p class="af-eyebrow">New workspace</p>
                        <h2 id="library-workspace-create-dialog-title">Create shared workspace</h2>
                        <p>Start a shared space and open it directly in the Library.</p>
                    </div>
                    <button class="artifactflow-editor-dialog-close" data-close-editor-dialog type="button" aria-label="Close workspace form">Close</button>
                </div>
                <form class="grid gap-4 p-6" method="POST" action="{{ route('workspaces.store') }}">
                    @csrf
                    <input name="return_to" type="hidden" value="library">
                    <label>
                        <span class="text-sm font-medium">Workspace name</span>
                        <input class="mt-2 w-full" name="name" type="text" maxlength="120" placeholder="Shared workspace" required>
                    </label>
                    <label>
                        <span class="text-sm font-medium">Parent workspace</span>
                        <select class="mt-2 w-full" name="parent_workspace_uid">
                            <option value="">No parent (top level)</option>
                            @foreach ($workspaceParentOptions as $parentOption)
                                <option value="{{ $parentOption->uid }}" @selected($currentWorkspaceUid === $parentOption->uid)>
                                    {{ str_repeat('— ', $parentOption->depth) }}{{ $parentOption->name }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex items-start gap-3">
                        <input name="inherits_parent_memberships" type="hidden" value="0">
                        <input class="mt-1" name="inherits_parent_memberships" type="checkbox" value="1" checked>
                        <span>
                            <strong class="block text-sm">Inherit parent members</strong>
                            <small class="block text-zinc-500 dark:text-zinc-400">Give current and future parent members access. Clear this to start with only you as admin.</small>
                        </span>
                    </label>
                    <div class="flex justify-end">
                        <button class="af-primary-button" type="submit">Create workspace</button>
                    </div>
                </form>
            </div>
        </dialog>
    </div>
</x-layouts.app>
